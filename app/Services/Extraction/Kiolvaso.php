<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\DokumentumAllapot;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Services\Extraction\Forras\Felderito;
use App\Services\Extraction\Xml\XmlKiolvaso;
use App\Services\Files\FajlTarolo;
use App\Support\Adoszam;
use App\Support\AfaBontas;
use App\Support\Ido;
use App\Support\Kredit;
use App\Support\Osszeg;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Egy dokumentum kiolvasása: fájl → modell → ellenőrzés → mentés.
 *
 * A nyers választ **mindig** eltároljuk, akkor is, ha az értelmezés utána
 * elszáll. Prompt- vagy modellcsere után ez az egyetlen mód visszamérni, hogy
 * tényleg jobb lett-e.
 */
final class Kiolvaso
{
    public function __construct(
        private readonly OpenRouterKliens $kliens,
        private readonly FajlTarolo $tarolo,
        private readonly Felderito $felderito,
        private readonly XmlKiolvaso $xmlKiolvaso,
    ) {}

    public function futtat(Document $dokumentum): void
    {
        try {
            $tartalom = $this->tarolo->tartalom($dokumentum);

            if ($tartalom === null) {
                $this->hibara($dokumentum, 'A dokumentum fájlja nem található.');

                return;
            }

            // Előbb megnézzük, honnan lehetne olvasni ezt a fájlt. Ma még minden
            // út a modellhez vezet, de a döntést már rögzítjük — ebből derül ki,
            // mennyi munkát lehet elvenni tőle.
            $forras = $this->felderito->felderit($tartalom, (string) $dokumentum->mime_type);

            // Tranzakcióba csomagolva: ha a mentés elszáll, a `hibara()` lenti
            // mentése ne ugyanabba az elakadt tranzakcióba fusson bele.
            DB::transaction(function () use ($dokumentum, $forras): void {
                $dokumentum->forceFill([
                    'forras_jelleg' => $forras->jelleg->value,
                    'forras_naplo' => $forras->naplo(),
                ])->save();
            });
        } catch (Throwable $e) {
            // A sikertelen mentés a most beállított mezőket piszkosan hagyná a
            // memóriában — enélkül a hibara() alábbi mentése ugyanabba a
            // (már nem létező oszlopra hivatkozó) hibába futna bele.
            $dokumentum->discardChanges();
            $this->hibara($dokumentum, 'Váratlan hiba a kiolvasás előkészítése közben.');

            report($e);

            return;
        }

        $kezdet = microtime(true);

        // A lánc legolcsóbb foka: ha a fájlban strukturált adat van, abból
        // olvasunk. Nem kerül semmibe, és nem találgatás — a kiállító maga
        // írta bele. Ha nem sikerül értelmezni, megyünk tovább a modellhez.
        if ($forras->xml !== null) {
            $xmlbol = $this->xmlKiolvaso->ertelmez($forras->xml);

            if ($xmlbol !== null) {
                $this->rogzit(
                    $dokumentum,
                    $xmlbol['nyers'],
                    ['motor' => $xmlbol['nev'], 'cost' => 0.0],
                    (int) ((microtime(true) - $kezdet) * 1000),
                );

                return;
            }
        }

        $ceg = $dokumentum->company;

        try {
            $valasz = $this->kliens->kiolvas(
                $tartalom,
                (string) $dokumentum->mime_type,
                (string) $dokumentum->original_filename,
                $ceg?->name,
                $ceg?->tax_number,
            );
        } catch (KiolvasasHiba $e) {
            $this->kiolvasasRogzites($dokumentum, null, null, $e->getMessage(), (int) ((microtime(true) - $kezdet) * 1000));
            $this->hibara($dokumentum, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->kiolvasasRogzites($dokumentum, null, null, $e->getMessage(), (int) ((microtime(true) - $kezdet) * 1000));
            $this->hibara($dokumentum, 'Váratlan hiba a kiolvasás közben.');

            report($e);

            return;
        }

        $this->rogzit($dokumentum, $valasz['fields'], $valasz, (int) ((microtime(true) - $kezdet) * 1000));
    }

    /**
     * A kiolvasás eredményének feldolgozása és mentése.
     *
     * Ide fut be mindkét út — a strukturált XML és a modell is —, mert a
     * tisztítás, a normalizálás, az ellenőrzések és a magabiztosság
     * ugyanaz mindkettőre. Egy csővezeték van, nem kettő: ami az egyik úton
     * javul, az a másikon is.
     *
     * @param  array<string, mixed>  $nyers  a kiolvasott mezők nyers alakja
     * @param  array<string, mixed>  $valasz  amit a kiolvasás soráról tudunk
     */
    private function rogzit(Document $dokumentum, array $nyers, array $valasz, int $idoMs): void
    {
        $tiszta = Sema::tisztit($nyers);
        $mezok = $this->normalizal($tiszta['mezok']);
        $mezok['afa_bontas'] = $this->normalizalBontas($tiszta['bontas']);
        $bukott = Validatorok::bukottak($mezok, $mezok['afa_bontas']);
        $konfidencia = Konfidencia::osszevon(
            $tiszta['konfidencia'],
            $bukott,
            $mezok,
            $tiszta['nehezen_olvashato'],
        );

        DB::transaction(function () use ($dokumentum, $valasz, $mezok, $konfidencia, $tiszta, $idoMs): void {
            $kiolvasas = $this->kiolvasasRogzites(
                $dokumentum,
                $mezok,
                $konfidencia,
                null,
                $idoMs,
                $valasz,
            );

            // A dokumentum oszlopai az **ember munkapéldánya**: innentől ezt
            // szerkeszti. A gépi érték a kiolvasás sorában marad, érintetlenül.
            $dokumentum->forceFill($mezok + [
                'tobb_irat_gyanu' => $tiszta['tobb_irat_gyanu'],
                'nehezen_olvashato' => $tiszta['nehezen_olvashato'],
                'status' => DokumentumAllapot::EllenorzesreVar->value,
                'claimed_at' => null,
                'error' => null,
            ])->save();

            unset($kiolvasas);
        });
    }

    /**
     * A modell által adott értékek a mi alakunkra hozva: a dátum ÉÉÉÉ-HH-NN, az
     * összeg tizedespontos, a pénznem nagybetűs, az adószám formázott.
     */
    private function normalizal(array $mezok): array
    {
        foreach (Sema::DATUM_MEZOK as $mezo) {
            $mezok[$mezo] = $mezok[$mezo] !== null ? Ido::datumErtelmez((string) $mezok[$mezo]) : null;
        }

        foreach (Sema::OSSZEG_MEZOK as $mezo) {
            if ($mezok[$mezo] === null) {
                continue;
            }

            $mezok[$mezo] = $this->osszeg($mezok[$mezo]);
        }

        if (is_string($mezok['currency']) && $mezok['currency'] !== '') {
            $mezok['currency'] = strtoupper(substr(trim($mezok['currency']), 0, 3));
        }

        foreach (['supplier_tax_number', 'customer_tax_number'] as $mezo) {
            if (is_string($mezok[$mezo])) {
                $mezok[$mezo] = Adoszam::formaz($mezok[$mezo]);
            }
        }

        return $mezok;
    }

    /**
     * Az ÁFA-bontás sorainak értelmezése: a kulcs számmá, az összegek a mi
     * alakunkra. Amelyik sorból nem marad használható kulcs és adóalap, az
     * kiesik — egy fél sor rosszabb, mint a hiánya, mert az összegellenőrzést
     * is elrontaná.
     *
     * @param  array<int, array<string, mixed>>|null  $bontas
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizalBontas(?array $bontas): ?array
    {
        if ($bontas === null) {
            return null;
        }

        $sorok = [];

        foreach ($bontas as $sor) {
            $kulcs = AfaBontas::kulcsErtelmez($sor['kulcs'] ?? null);
            $netto = $this->osszeg($sor['netto'] ?? null);

            if ($kulcs === null || $netto === null) {
                continue;
            }

            $sorok[] = [
                'kulcs' => $kulcs,
                'kategoria' => $sor['kategoria'] ?? null,
                'netto' => $netto,
                'afa' => $this->osszeg($sor['afa'] ?? null),
            ];
        }

        return $sorok === [] ? null : $sorok;
    }

    /** Összeg a mi alakunkra hozva; amit nem értünk, az null. */
    private function osszeg(mixed $ertek): ?string
    {
        if ($ertek === null || $ertek === '') {
            return null;
        }

        $eredmeny = Osszeg::ertelmez(is_string($ertek) ? $ertek : (float) $ertek);

        return $eredmeny->ok ? $eredmeny->ertek : null;
    }

    private function kiolvasasRogzites(
        Document $dokumentum,
        ?array $mezok,
        ?array $konfidencia,
        ?string $hiba,
        int $idoMs,
        ?array $valasz = null,
    ): DocumentExtraction {
        // Ha a hívó megnevezte a motort, akkor nem a modell futott, hanem az
        // XML-értelmező — ott pedig nincs prompt. A prompt verziójának
        // odaírása elrontaná az összehasonlítást, amiért az oszlop van.
        $sajatMotor = isset($valasz['motor']);

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'model' => (string) ($valasz['motor'] ?? config('openrouter.model')),
            'model_version' => $valasz['model'] ?? null,
            'prompt_version' => $sajatMotor ? null : Prompt::VERZIO,
            'raw_response' => $valasz['raw'] ?? null,
            'fields' => $mezok,
            'confidence' => $konfidencia,
            'input_tokens' => $valasz['input_tokens'] ?? null,
            'output_tokens' => $valasz['output_tokens'] ?? null,
            'cost' => $valasz['cost'] ?? null,
            'duration_ms' => $idoMs,
            // A keret oldalarányosan fogy. Az oldalszámot a felderítés írta a
            // dokumentum naplójába, még a kiolvasás előtt — innen olvassuk,
            // hogy mindkét út (XML és modell) ugyanazt a számot kapja.
            'credits' => Kredit::oldalakbol($this->oldalszam($dokumentum)),
            'error' => $hiba,
        ]);
        $kiolvasas->company_id = $dokumentum->company_id;
        $kiolvasas->save();

        return $kiolvasas;
    }

    private function oldalszam(Document $dokumentum): ?int
    {
        $oldalak = ((array) $dokumentum->forras_naplo)['oldalszam'] ?? null;

        return is_numeric($oldalak) ? (int) $oldalak : null;
    }

    /**
     * Hiba után: amíg van próbálkozás hátra, visszatesszük a sorba; utána
     * megáll, és a Beérkezőben látszik, mi történt. A néma újrapróbálkozás
     * végtelenségig égetné a pénzt.
     */
    private function hibara(Document $dokumentum, string $uzenet): void
    {
        $max = (int) config('szamlafolyo.extraction.max_attempts');

        $dokumentum->forceFill([
            'status' => $dokumentum->attempts >= $max
                ? DokumentumAllapot::Hiba->value
                : DokumentumAllapot::Feltoltve->value,
            'claimed_at' => null,
            'error' => $uzenet,
        ])->save();
    }
}
