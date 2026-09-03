<?php

declare(strict_types=1);

namespace App\Services\Extraction\Xml;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Egy e-számla-formátum értelmezője.
 *
 * A leszármazottak ugyanazt az alakot adják vissza, amit a modell is ad — így
 * a Kiolvasó ugyanazon a `Sema::tisztit()` → normalizálás → validátorok úton
 * viszi tovább, akárhonnan jött az adat. Egy csővezeték van, nem kettő.
 *
 * Az útvonalak szándékosan `local-name()`-mel dolgoznak, nem regisztrált
 * névtér-prefixekkel: ugyanaz a bizonylat a ZUGFeRD 1.0, a ZUGFeRD 2.x, a
 * Factur-X és az XRechnung kezében más névtér-URI-t és más prefixet kap, a
 * elemnevek viszont ugyanazok maradnak.
 */
abstract class Ertelmezo
{
    /** Felismeri-e ez az értelmező a dokumentumot. */
    abstract public function tamogatja(DOMDocument $doc): bool;

    /** A formátum neve — ez kerül a kiolvasás sorába a modell neve helyére. */
    abstract public function nev(): string;

    /**
     * A kanonikus kiolvasási tömb: ugyanazok a kulcsok, amiket a modell tölt.
     *
     * @return array<string, mixed>
     */
    abstract public function ertelmez(DOMXPath $xpath): array;

    /**
     * Útvonal elemnevekből, névtértől függetlenül.
     *
     * A `ut('ExchangedDocument', 'ID')` a `.//ExchangedDocument/ID`-t jelenti,
     * bármelyik névtérben is álljon.
     */
    protected function ut(string ...$nevek): string
    {
        $lepesek = array_map(
            static fn (string $nev): string => '*[local-name()="'.$nev.'"]',
            $nevek,
        );

        return './/'.implode('/', $lepesek);
    }

    /** Az első találat szövege, vagy null. */
    protected function szoveg(DOMXPath $xpath, string $ut, ?DOMNode $honnan = null): ?string
    {
        $talalat = $honnan === null ? $xpath->query($ut) : $xpath->query($ut, $honnan);

        if ($talalat === false || $talalat->length === 0) {
            return null;
        }

        $ertek = trim((string) $talalat->item(0)?->textContent);

        return $ertek === '' ? null : $ertek;
    }

    /**
     * Összeg számként. A `1234.56` alakot minden e-számla-formátum előírja,
     * ezért itt nem kell magyar írásmóddal küzdeni — de ami mégsem szám, azt
     * inkább eldobjuk, mint hogy nullát írjunk a helyére.
     */
    protected function szam(DOMXPath $xpath, string $ut, ?DOMNode $honnan = null): ?float
    {
        $ertek = $this->szoveg($xpath, $ut, $honnan);

        return $ertek !== null && is_numeric($ertek) ? (float) $ertek : null;
    }

    /**
     * Dátum ÉÉÉÉ-HH-NN alakra.
     *
     * A CII a `20260314` alakot használja (UNCL2379 „102" formátum), az UBL az
     * ISO-t. Mindkettőt idehozzuk, hogy a lánc többi része egyfélével dolgozzon.
     */
    protected function datum(?string $ertek): ?string
    {
        if ($ertek === null) {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ertek, $resz) === 1) {
            return "{$resz[1]}-{$resz[2]}-{$resz[3]}";
        }

        // Az ISO dátum lehet időbélyeggel is; a nap elég nekünk.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $ertek, $resz) === 1) {
            return $resz[1];
        }

        return null;
    }

    /**
     * A kitöltött mezők magabiztossága. A strukturált adat nem találgatás:
     * ami benne van, az pontosan az, amit a kiállító beleírt. A validátorok
     * ettől még lehúzhatják — egy hibásan összeadott XML is hibás.
     *
     * @param  array<string, mixed>  $mezok
     * @return array<string, float>
     */
    protected function konfidencia(array $mezok, bool $vanBontas): array
    {
        $pontok = [];

        foreach ($mezok as $mezo => $ertek) {
            if ($ertek !== null && $ertek !== [] && $mezo !== 'afa_bontas') {
                $pontok[$mezo] = 1.0;
            }
        }

        if ($vanBontas) {
            $pontok['afa_bontas'] = 1.0;
        }

        return $pontok;
    }
}
