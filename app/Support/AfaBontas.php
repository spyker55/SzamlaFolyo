<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Az eltárolt ÁFA-bontás kijelzésre előkészítve.
 *
 * A soronkénti bruttót **nem tároljuk**: az EN 16931 sem tárolja (csak
 * adóalapot és adóösszeget), és a származtatott érték idővel el tud csúszni
 * attól, amiből származik. Itt számoljuk ki, egy helyen — az ellenőrző
 * képernyő és a próbaparancs is ezt használja.
 *
 * A kulcs típusa a json oda-vissza úton nem állandó: a `json_encode(27.0)`
 * „27"-et ír, tehát az egész kulcs int-ként jön vissza, a törtes (7,5%) meg
 * float-ként. Ezért mindenhol `is_numeric` dönt, sosem típus-egyezés.
 */
final class AfaBontas
{
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
                'kulcs' => self::kulcs($sor['kulcs'] ?? null),
                'kategoria' => is_string($sor['kategoria'] ?? null) ? $sor['kategoria'] : null,
                'netto' => $netto,
                'afa' => $afa,
                'brutto' => $netto === null ? null : number_format((float) $netto + (float) $afa, 2, '.', ''),
            ];
        }

        return $sorok;
    }

    /** A kulcs kijelzési alakja: 27, nem 27,00 — de 7,5 marad 7,5. */
    private static function kulcs(mixed $ertek): string
    {
        if (! is_numeric($ertek)) {
            return '—';
        }

        $szam = (float) $ertek;

        return $szam === floor($szam)
            ? (string) (int) $szam
            : rtrim(number_format($szam, 2, ',', ''), '0');
    }

    private static function szoveg(mixed $ertek): ?string
    {
        return $ertek === null || $ertek === '' ? null : (string) $ertek;
    }
}
