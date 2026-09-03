<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Company;
use App\Models\Document;
use App\Services\Billing\Kvota;
use App\Services\Extraction\Kiolvaso;
use App\Services\Extraction\Konfidencia;
use App\Services\Extraction\Prompt;
use App\Services\Extraction\Sema;
use App\Services\Files\FajlHiba;
use App\Services\Files\FajlTarolo;
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
        {--megtart : Maradjon bent a Beérkezőben ellenőrzésre}';

    protected $description = 'Kiolvas egy bizonylatot, és kiírja, mit ismert fel a modell.';

    public function handle(FajlTarolo $tarolo, Kiolvaso $kiolvaso, Berlo $berlo): int
    {
        $utvonal = (string) $this->argument('fajl');

        if (! is_file($utvonal) || ! is_readable($utvonal)) {
            $this->error("Nem olvasható fájl: {$utvonal}");

            return self::FAILURE;
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

    private function ceg(): ?Company
    {
        $ceg = $this->option('ceg') !== null
            ? Company::query()->find((int) $this->option('ceg'))
            : Company::query()->orderBy('id')->first();

        if ($ceg === null) {
            $this->error($this->option('ceg') !== null
                ? 'Nincs ilyen azonosítójú cég.'
                : 'Még egy cég sincs a rendszerben — előbb regisztrálj és nyiss céget.');
        }

        return $ceg;
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

        $konfidencia = (array) ($kiolvasas->confidence['combined'] ?? []);
        $bukott = (array) ($kiolvasas->confidence['validators'] ?? []);

        $this->line('');
        $this->line('  <options=bold>Amit a modell kiolvasott</>');
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
        $this->line(sprintf(
            '  <fg=gray>%s be- / %s kimenő token · %s · %s mp · futtatott modell: %s</>',
            number_format((float) ($kiolvasas->input_tokens ?? 0), 0, ',', ' '),
            number_format((float) ($kiolvasas->output_tokens ?? 0), 0, ',', ' '),
            $kiolvasas->cost !== null ? '$'.rtrim(rtrim(number_format((float) $kiolvasas->cost, 4, '.', ''), '0'), '.') : 'ismeretlen költség',
            number_format($masodperc, 1, ',', ' '),
            $kiolvasas->model_version ?? '—',
        ));

        return true;
    }

    private function lezaras(Document $dokumentum, Company $ceg): void
    {
        if ($this->option('megtart')) {
            $this->line('');
            $this->line("  <fg=green>A tétel a Beérkezőben maradt (#{$dokumentum->id}), ellenőrizhető a felületen.</>");
            $this->line('');

            return;
        }

        app(FajlTarolo::class)->torol($dokumentum);
        $dokumentum->delete();

        $kvota = new Kvota($ceg);
        $maradek = $kvota->maradek();

        $this->line('');
        $this->line('  <fg=gray>A próbatétel törölve — nem fogyasztotta a keretet.'
            .($maradek === PHP_INT_MAX ? '' : " Hátralévő keret: {$maradek} dokumentum.")
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
            in_array($mezo, ['net_amount', 'vat_amount', 'gross_amount'], true) => Osszeg::formaz($ertek),
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
