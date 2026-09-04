<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AfaKategoria;
use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Company;
use App\Models\Document;
use App\Services\Billing\Kvota;
use App\Services\Extraction\Forras\Jelleg;
use App\Services\Extraction\Kiolvaso;
use App\Services\Extraction\Konfidencia;
use App\Services\Extraction\Prompt;
use App\Services\Extraction\Sema;
use App\Services\Files\FajlHiba;
use App\Services\Files\FajlTarolo;
use App\Support\AfaBontas;
use App\Support\Berlo;
use App\Support\Osszeg;
use Illuminate\Console\Command;

/**
 * Egy bizonylat kiolvasása a parancssorból, a webfelület megkerülésével.
 *
 * Két dologra való. Először: éles próbára, amikor a felület valamiért nem
 * érhető el — ez a leggyorsabb út annak eldöntéséhez, hogy a modellnév, a
 * kulcs és a PDF-formátum stimmel-e. Másodszor, és tartósan: prompt- vagy
 * modellcsere után ezzel mérhető le ugyanazon a bizonylaton, hogy javult-e a
 * pontosság — a kimenet ezért írja ki a prompt verzióját és a költséget is.
 *
 * A próba **valódi pénzbe kerül**, mert valódi modellhívás. A vizsgált iratot
 * alapból törli maga után, hogy ne szemetelje tele a Beérkezőt és ne fogyassza
 * a cég keretét.
 */
final class KiolvasasProba extends Command
{
    protected $signature = 'kiolvasas:proba
        {fajl : A vizsgálandó bizonylat elérési útja}
        {--ceg= : Melyik cég nevében (azonosító); alapból az első}
        {--ceg-nev= : Ideiglenes cég neve, ha még egy sincs (alapból: Próba Kft.)}
        {--modell= : Ezzel a modellel olvassunk ki, a beállított helyett}
        {--megtart : Maradjon bent a Beérkezőben ellenőrzésre}';

    protected $description = 'Kiolvas egy bizonylatot, és kiírja, mit ismert fel a modell.';

    /** Mi hoztuk létre a céget, tehát mi is takarítjuk el. */
    private bool $ideiglenesCeg = false;

    public function handle(FajlTarolo $tarolo, Kiolvaso $kiolvaso, Berlo $berlo): int
    {
        $utvonal = (string) $this->argument('fajl');

        if (! is_file($utvonal) || ! is_readable($utvonal)) {
            $this->error("Nem olvasható fájl: {$utvonal}");

            return self::FAILURE;
        }

        $this->nyilvanosHelyEllenorzes($utvonal);

        // Futásidőben írjuk felül, nem környezeti változóval: élesben a
        // konfiguráció gyorsítótárazva van (`config:cache`), ott az env()
        // értéke már nem érvényesülne. Így két modell ugyanazon a bizonylaton
        // egy-egy paranccsal összemérhető.
        if ($this->option('modell') !== null) {
            config(['openrouter.model' => (string) $this->option('modell')]);
        }

        $ceg = $this->ceg();

        if ($ceg === null) {
            return self::FAILURE;
        }

        $tartalom = (string) file_get_contents($utvonal);

        return $berlo->nevében($ceg, function () use ($tarolo, $kiolvaso, $ceg, $tartalom, $utvonal): int {
            try {
                $dokumentum = $tarolo->tarol($ceg, $tartalom, basename($utvonal));
            } catch (FajlHiba $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            // Ugyanazt a fájlt kétszer megpróbálni teljesen jogos igény egy
            // próbánál — a duplikátum-szabály a Beérkezőnek szól, nem ennek.
            if ($dokumentum->status === DokumentumAllapot::Duplikatum) {
                $this->line('  <fg=gray>(ez a fájl már szerepel a rendszerben; a próba kedvéért mégis kiolvassuk)</>');
                $dokumentum->update([
                    'status' => DokumentumAllapot::Feltoltve->value,
                    'duplicate_of_id' => null,
                ]);
            }

            $this->fejlec($dokumentum, $ceg);

            $kezdet = microtime(true);
            $kiolvaso->futtat($dokumentum);
            $dokumentum->refresh();

            $sikeres = $this->eredmeny($dokumentum, microtime(true) - $kezdet);

            $this->lezaras($dokumentum, $ceg);

            return $sikeres ? self::SUCCESS : self::FAILURE;
        });
    }

    /**
     * A tárhelyen a webcímhez tartozó FTP-fiók a `public/` mappába lép be —
     * oda a legkönnyebb feltölteni, és pont az a webgyökér. Egy valódi
     * ügyfélszámla partnernevekkel és összegekkel onnan bárkinek letölthető.
     *
     * A kiolvasást ettől még lefuttatjuk: a felhasználó tudja, mit csinál, egy
     * próbaparancsnak pedig nem dolga megtagadni a munkát. A dolga az, hogy ne
     * hagyja csendben.
     */
    private function nyilvanosHelyEllenorzes(string $utvonal): void
    {
        $valodi = realpath($utvonal);
        $nyilvanos = realpath(public_path());

        if ($valodi === false || $nyilvanos === false || ! str_starts_with($valodi, $nyilvanos.DIRECTORY_SEPARATOR)) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/').'/'.ltrim(str_replace('\\', '/', substr($valodi, strlen($nyilvanos))), '/');

        $this->line('');
        $this->line('  <fg=yellow;options=bold>! Ez a fájl a webgyökérben van, tehát bárki letöltheti:</>');
        $this->line("  <fg=yellow>{$url}</>");
        $this->line('  <fg=gray>Tedd a home könyvtárba (pl. ~/szamla.pdf), és töröld innen —</>');
        $this->line('  <fg=gray>egy valódi bizonylatnak nem a nyilvános mappában a helye.</>');
    }

    /**
     * A próba akkor is működjön, amikor még egy cég sincs — a cégnyitás a
     * webfelületen történne, és épp az lehet elérhetetlen (ez a parancs
     * részben pont ezért van). Ilyenkor csinálunk egy ideiglenes céget, és a
     * végén el is takarítjuk.
     */
    private function ceg(): ?Company
    {
        if ($this->option('ceg') !== null) {
            $ceg = Company::query()->find((int) $this->option('ceg'));

            if ($ceg === null) {
                $this->error('Nincs ilyen azonosítójú cég.');
            }

            return $ceg;
        }

        $ceg = Company::query()->orderBy('id')->first();

        if ($ceg !== null) {
            return $ceg;
        }

        // A cég neve és adószáma a promptba is bekerül (abból dönti el a
        // modell, melyik fél a partner), ezért érdemes a valódit megadni:
        //   --ceg-nev="Példa Kereskedelmi Kft."
        $this->ideiglenesCeg = true;

        return Company::create([
            'name' => (string) ($this->option('ceg-nev') ?: 'Próba Kft.'),
        ]);
    }

    private function fejlec(Document $dokumentum, Company $ceg): void
    {
        $this->line('');
        $this->line(sprintf(
            '  <options=bold>%s</> · %s · %s',
            $dokumentum->original_filename,
            $this->meret((int) $dokumentum->size_bytes),
            $dokumentum->mime_type,
        ));
        $this->line(sprintf(
            '  <fg=gray>cég: %s · modell: %s · prompt: %s</>',
            $ceg->name,
            (string) config('openrouter.model'),
            Prompt::VERZIO,
        ));
        $this->line('');
        $this->line('  <fg=gray>Kiolvasás folyamatban…</>');
    }

    private function eredmeny(Document $dokumentum, float $masodperc): bool
    {
        $kiolvasas = $dokumentum->utolsoKiolvasas();

        if ($kiolvasas === null || $kiolvasas->error !== null) {
            $this->line('');
            $this->line('  <fg=red;options=bold>A kiolvasás nem sikerült.</>');
            $this->line('  '.($kiolvasas?->error ?? $dokumentum->error ?? 'Ismeretlen hiba.'));
            $this->line('');
            $this->line('  <fg=gray>Amit érdemes megnézni: az OPENROUTER_API_KEY érvényes-e, az</>');
            $this->line('  <fg=gray>OPENROUTER_MODEL létező modellnév-e, és hogy a modell fogad-e PDF-et.</>');
            $this->line('');

            return false;
        }

        // Az XML-értelmező sosem hív modellt, ezért nincs prompt-verziója.
        $xmlbol = $kiolvasas->prompt_version === null;

        $this->forras($dokumentum, $xmlbol);

        $konfidencia = (array) ($kiolvasas->confidence['combined'] ?? []);
        $bukott = (array) ($kiolvasas->confidence['validators'] ?? []);

        $this->line('');
        $this->line($xmlbol
            ? '  <options=bold>Amit az XML tartalmazott</>'
            : '  <options=bold>Amit a modell kiolvasott</>');
        $this->line('');

        foreach (Sema::MEZOK as $mezo) {
            $ertek = $this->ertek($dokumentum, $mezo);
            $pont = $konfidencia[$mezo] ?? null;

            $this->line(sprintf(
                '  %s %s %s',
                $this->oszlop(Sema::CIMKEK[$mezo], 22),
                $ertek === null ? $this->oszlop('—', 32) : $this->oszlop($ertek, 32),
                $this->jelzo($pont),
            ));
        }

        $this->bontas($dokumentum, $konfidencia['afa_bontas'] ?? null);

        if ($dokumentum->nehezen_olvashato) {
            $this->line('');
            $this->line('  <fg=yellow>! A modell szerint a bizonylat kézzel írott vagy nehezen olvasható.</>');
            $this->line('  <fg=yellow>  Ilyenkor minden mezőt össze kell vetni a papírral — a neveket különösen.</>');
        }

        if ($dokumentum->tobb_irat_gyanu) {
            $this->line('');
            $this->line('  <fg=yellow>! A modell szerint több bizonylat van ebben a fájlban.</>');
        }

        if ($bukott !== []) {
            $this->line('');
            $this->line('  <options=bold>Amit az ellenőrzések kifogásoltak</>');
            $this->line('');
            foreach ($bukott as $mezo => $indok) {
                $this->line(sprintf('  <fg=red>✗</> %s: %s', Sema::CIMKEK[$mezo] ?? $mezo, $indok));
            }
        }

        $this->line('');
        $this->line($xmlbol
            ? sprintf(
                '  <fg=gray>%s mp · értelmező: %s · <fg=green>0 token, 0 forint</></>',
                number_format($masodperc, 1, ',', ' '),
                $kiolvasas->model,
            )
            : sprintf(
                '  <fg=gray>%s be- / %s kimenő token · %s · %s mp · futtatott modell: %s</>',
                number_format((float) ($kiolvasas->input_tokens ?? 0), 0, ',', ' '),
                number_format((float) ($kiolvasas->output_tokens ?? 0), 0, ',', ' '),
                $kiolvasas->cost !== null ? '$'.rtrim(rtrim(number_format((float) $kiolvasas->cost, 4, '.', ''), '0'), '.') : 'ismeretlen költség',
                number_format($masodperc, 1, ',', ' '),
                $kiolvasas->model_version ?? '—',
            ));

        return true;
    }

    /**
     * Honnan lehetett volna olvasni ezt a fájlt. Ez a lánc lényege: amit
     * strukturáltan is meg lehet kapni, azért ne fizessünk modellhívást.
     */
    private function forras(Document $dokumentum, bool $xmlbol): void
    {
        $jelleg = Jelleg::tryFrom((string) $dokumentum->forras_jelleg);

        if ($jelleg === null) {
            return;
        }

        $naplo = (array) $dokumentum->forras_naplo;
        $reszlet = match ($jelleg) {
            Jelleg::StrukturaltXml, Jelleg::BeagyazottXml => sprintf('%d bájt XML', $naplo['xml_bajt'] ?? 0),
            Jelleg::Szovegreteg => sprintf('%d karakter szöveg', $naplo['szoveg_hossz'] ?? 0),
            Jelleg::Kep => 'nincs kiolvasható szöveg',
        };

        $this->line('');
        $this->line(sprintf('  <options=bold>Forrás:</> %s <fg=gray>(%s)</>', $jelleg->cimke(), $reszlet));

        if ($xmlbol) {
            $this->line('  <fg=green>A strukturált adatból olvastuk ki — modellhívás nem történt.</>');

            return;
        }

        if (! $jelleg->igenyelModellt()) {
            $this->line('  <fg=yellow>Van benne strukturált adat, de nem sikerült értelmezni — a modell olvasta.</>');
        }
    }

    /**
     * A kulcsonkénti ÁFA-bontás. Ez a próba egyik lényegi kérdése: a modell
     * kulcsonként összesít-e, vagy tételsorokat sorol fel.
     */
    private function bontas(Document $dokumentum, ?float $pont): void
    {
        $sorok = AfaBontas::sorok($dokumentum->afa_bontas);

        if ($sorok === []) {
            return;
        }

        $this->line('');
        $this->line(sprintf('  <options=bold>ÁFA-bontás</> %s', $this->jelzo($pont)));
        $this->line('');

        $this->line(sprintf(
            '  %s%s%s%s',
            $this->oszlop('Kulcs', 8),
            $this->oszlop('Kategória', 24),
            $this->oszlop('Nettó', 16),
            $this->oszlop('ÁFA', 16),
        ));

        foreach ($sorok as $sor) {
            $this->line(sprintf(
                '  %s%s%s%s',
                $this->oszlop($sor['kulcs'].'%', 8),
                $this->oszlop(AfaKategoria::cimkeje($sor['kategoria']), 24),
                $this->oszlop(Osszeg::formaz($sor['netto']), 16),
                $this->oszlop(Osszeg::formaz($sor['afa']), 16),
            ));
        }
    }

    private function lezaras(Document $dokumentum, Company $ceg): void
    {
        $this->line('');

        if ($this->option('megtart')) {
            $this->line("  <fg=green>A tétel a Beérkezőben maradt (#{$dokumentum->id}), ellenőrizhető a felületen.</>");

            if ($this->ideiglenesCeg) {
                $this->line("  <fg=yellow>Ehhez létrejött a(z) „{$ceg->name}” cég is (#{$ceg->id}) — ha nem kell, töröld.</>");
            }

            $this->line('');

            return;
        }

        app(FajlTarolo::class)->torol($dokumentum);

        // A kiolvasás sora túléli a dokumentumot (abból számol a keret), ezért
        // a próbáét külön visszük el. A parancs kiírja, hogy nem fogyasztott —
        // ez teszi igazzá.
        $dokumentum->extractions()->delete();
        $dokumentum->delete();

        if ($this->ideiglenesCeg) {
            $ceg->delete();
            $this->line('  <fg=gray>A próbatétel és az ideiglenes cég törölve.</>');
            $this->line('  <fg=gray>Éles méréshez add meg a valódi cégnevet: --ceg-nev="Példa Kft." —</>');
            $this->line('  <fg=gray>a modell ebből tudja eldönteni, melyik fél a partner.</>');
            $this->line('');

            return;
        }

        $maradek = (new Kvota($ceg))->maradek();

        $this->line('  <fg=gray>A próbatétel törölve — nem fogyasztotta a keretet.'
            ." Hátralévő keret: {$maradek} dokumentum."
            .' A --megtart kapcsolóval bent marad.</>');
        $this->line('');
    }

    private function ertek(Document $dokumentum, string $mezo): ?string
    {
        $ertek = $dokumentum->{$mezo};

        return match (true) {
            $ertek === null || $ertek === '' => null,
            $ertek instanceof DokumentumTipus => $ertek->cimke(),
            $ertek instanceof \DateTimeInterface => $ertek->format('Y. m. d.'),
            in_array($mezo, Sema::OSSZEG_MEZOK, true) => Osszeg::formaz($ertek),
            default => (string) $ertek,
        };
    }

    /** A három konfidencia-sáv, ugyanazzal a küszöbbel, mint az ellenőrző képernyőn. */
    private function jelzo(?float $pont): string
    {
        if ($pont === null) {
            return '';
        }

        $szin = match (Konfidencia::sav($pont)) {
            'gyanus' => 'red',
            'bizonytalan' => 'yellow',
            default => 'green',
        };

        return sprintf('<fg=%s>●</> <fg=gray>%s</>', $szin, number_format($pont, 2, ',', ''));
    }

    /**
     * Oszlopigazítás ékezetes szöveghez. A `sprintf` szélessége bájtban
     * számol, a magyar ékezetes betűk viszont két bájtosak — enélkül a
     * táblázat minden ékezetes címkénél elcsúszik.
     */
    private function oszlop(string $szoveg, int $szelesseg): string
    {
        $hossz = mb_strlen($szoveg);

        if ($hossz >= $szelesseg) {
            return mb_substr($szoveg, 0, $szelesseg);
        }

        return $szoveg.str_repeat(' ', $szelesseg - $hossz);
    }

    private function meret(int $bajt): string
    {
        return $bajt >= 1048576
            ? number_format($bajt / 1048576, 1, ',', ' ').' MB'
            : number_format($bajt / 1024, 1, ',', ' ').' KB';
    }
}
