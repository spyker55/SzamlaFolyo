<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Az ÁFA-bontás számtana — egy helyen.
 *
 * Három hívója van, és pontosan ezért van külön osztályban: a kiolvasó
 * (a modell nyers kulcsát értelmezi), az ellenőrző képernyő (az ember
 * gépelését, és soronként bruttót mutat), valamint az export (kulcsonkénti
 * oszlopokba összegez). Ha ez a három külön számolna, a könyvelő más számot
 * látna az Excelben, mint a képernyőn.
 *
 * A soronkénti bruttót **nem tároljuk**: az EN 16931 sem tárolja (csak
 * adóalapot és adóösszeget), és a származtatott érték idővel el tud csúszni
 * attól, amiből származik. Itt számoljuk ki, minden hívónak ugyanúgy.
 *
 * A kulcs típusa a json oda-vissza úton nem állandó: a `json_encode(27.0)`
 * „27"-et ír, tehát az egész kulcs int-ként jön vissza, a törtes (7,5%) meg
 * float-ként. Ezért mindenhol `is_numeric` dönt, sosem típus-egyezés.
 */
final class AfaBontas
{
    /**
     * A magyar törvényes kulcsok. Ezek kapnak saját exportoszlopot; minden
     * más (külföldi számla 19%-a, 21%-a) az „egyéb" vödörbe megy.
     */
    public const KULCSOK = [27, 18, 5];

    /**
     * Az export kulcsonkénti oszlopai, ebben a sorrendben.
     *
     * A nulla kulcsnak nincs ÁFA-oszlopa: nullától ÁFA nem keletkezik. Amelyik
     * „0%-os" soron mégis van adó, az nem nulla kulcsos sor — azt egészében az
     * „egyéb" vödör viszi (lásd `vodor()`), hogy pénz ne tűnjön el csendben.
     */
    public const OSZLOPOK = [
        'netto_27', 'afa_27',
        'netto_18', 'afa_18',
        'netto_5', 'afa_5',
        'netto_0',
        'netto_egyeb', 'afa_egyeb',
    ];

    /**
     * @param  array<int, array<string, mixed>>|null  $bontas
     * @return array<int, array{kulcs: string, kategoria: ?string, netto: ?string, afa: ?string, brutto: ?string}>
     */
    public static function sorok(?array $bontas): array
    {
        if (! is_array($bontas)) {
            return [];
        }

        $sorok = [];

        foreach ($bontas as $sor) {
            if (! is_array($sor)) {
                continue;
            }

            $netto = self::szoveg($sor['netto'] ?? null);
            $afa = self::szoveg($sor['afa'] ?? null);

            $sorok[] = [
                'kulcs' => self::kulcsKiiras($sor['kulcs'] ?? null),
                'kategoria' => is_string($sor['kategoria'] ?? null) ? $sor['kategoria'] : null,
                'netto' => $netto,
                'afa' => $afa,
                'brutto' => self::brutto($netto, $afa),
            ];
        }

        return $sorok;
    }

    /**
     * Az ÁFA-kulcs számmá. A „27%", a „27,0" és a 27 is 27.0.
     *
     * Ugyanez az értelmező szolgálja ki a modellt és az embert: a papírról
     * gépelő ember ugyanúgy odaírja a százalékjelet, mint a modell.
     */
    public static function kulcsErtelmez(mixed $ertek): ?float
    {
        if (is_int($ertek) || is_float($ertek)) {
            return (float) $ertek;
        }

        if (! is_string($ertek)) {
            return null;
        }

        $tiszta = str_replace([' ', '%', ','], ['', '', '.'], trim($ertek));

        return is_numeric($tiszta) ? (float) $tiszta : null;
    }

    /**
     * A sor bruttója: adóalap + adóösszeg. ÁFA nélkül a nettó önmaga a bruttó
     * (fordított adózás, mentesség) — adóalap nélkül viszont nincs mit
     * kiírni, mert a puszta ÁFA-összeg nem sor.
     */
    public static function brutto(mixed $netto, mixed $afa): ?string
    {
        $alap = self::szam($netto);

        if ($alap === null) {
            return null;
        }

        return number_format($alap + (self::szam($afa) ?? 0.0), 2, '.', '');
    }

    /**
     * Kulcsonkénti összegzés az exporthoz.
     *
     * Kulcsonként összead, nem soronként: emberi szerkesztés után ugyanaz a
     * kulcs több sorban is szerepelhet, és két 27%-os sor összege továbbra is
     * egyetlen 27%-os adóalap.
     *
     * @param  array<int, array<string, mixed>>|null  $bontas
     * @return array<string, float|null> az `OSZLOPOK` kulcsaival
     */
    public static function vodrok(?array $bontas): array
    {
        $eredmeny = array_fill_keys(self::OSZLOPOK, null);

        if (! is_array($bontas)) {
            return $eredmeny;
        }

        foreach ($bontas as $sor) {
            if (! is_array($sor)) {
                continue;
            }

            $netto = self::szam($sor['netto'] ?? null);
            $afa = self::szam($sor['afa'] ?? null);

            if ($netto === null && $afa === null) {
                continue;
            }

            $vodor = self::vodor(self::kulcsErtelmez($sor['kulcs'] ?? null), $afa);

            $eredmeny['netto_'.$vodor] = ($eredmeny['netto_'.$vodor] ?? 0.0) + ($netto ?? 0.0);

            // A nulla vödörnek nincs ÁFA-oszlopa, és nem is kerülhet ide olyan
            // sor, amin van adó — a `vodor()` az ilyet már átirányította.
            if ($vodor !== '0') {
                $eredmeny['afa_'.$vodor] = ($eredmeny['afa_'.$vodor] ?? 0.0) + ($afa ?? 0.0);
            }
        }

        // A lebegőpontos összeadás sodródását itt egyszer visszavágjuk.
        foreach ($eredmeny as $oszlop => $ertek) {
            $eredmeny[$oszlop] = $ertek === null ? null : round($ertek, 2);
        }

        return $eredmeny;
    }

    /** Melyik vödörbe tartozik a sor. */
    private static function vodor(?float $kulcs, ?float $afa): string
    {
        if ($kulcs === null) {
            return 'egyeb';
        }

        foreach (self::KULCSOK as $ismert) {
            if (abs($kulcs - $ismert) < 0.001) {
                return (string) $ismert;
            }
        }

        if (abs($kulcs) < 0.001) {
            // Nullától ÁFA nem keletkezik. Ha mégis van a soron, akkor vagy a
            // kulcs rossz, vagy az összeg — nem tudjuk, melyik, de a nulla
            // oszlopban egyik sem fér el. A tizedes tárolási pontossága két
            // jegy, ezért ami ez alatt van, az nulla.
            return $afa !== null && abs($afa) >= 0.005 ? 'egyeb' : '0';
        }

        return 'egyeb';
    }

    /** A kulcs kijelzési alakja: 27, nem 27,00 — de 7,5 marad 7,5. */
    private static function kulcsKiiras(mixed $ertek): string
    {
        $szam = self::kulcsErtelmez($ertek);

        if ($szam === null) {
            return '—';
        }

        return $szam === floor($szam)
            ? (string) (int) $szam
            : rtrim(number_format($szam, 2, ',', ''), '0');
    }

    /** Összeg számmá; amit nem értünk, az null — nem nulla. */
    private static function szam(mixed $ertek): ?float
    {
        if ($ertek === null || $ertek === '') {
            return null;
        }

        if (is_int($ertek) || is_float($ertek)) {
            return (float) $ertek;
        }

        if (! is_string($ertek)) {
            return null;
        }

        $eredmeny = Osszeg::ertelmez($ertek);

        return $eredmeny->ok && $eredmeny->ertek !== null ? (float) $eredmeny->ertek : null;
    }

    private static function szoveg(mixed $ertek): ?string
    {
        return $ertek === null || $ertek === '' ? null : (string) $ertek;
    }
}
