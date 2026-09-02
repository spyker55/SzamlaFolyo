<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Magyar adószám: `12345678-2-42`.
 *
 * A 8. számjegy ellenőrző számjegy az első hét fölött (9-7-3-1-9-7-3 súlyozás),
 * a 9. az ÁFA-kód (1–5), az utolsó kettő a megyekód. Ez a legerősebb
 * determinisztikus jelünk arra, hogy a modell jól olvasta-e ki a partnert.
 *
 * Amit biztosan nem tudunk eldönteni, azt nem utasítjuk el: külföldi
 * regisztrációs szám vagy EU-s adószám nem magyar alakú, és attól még helyes.
 */
final class Adoszam
{
    private const SULYOK = [9, 7, 3, 1, 9, 7, 3];

    /** Csak a számjegyek. */
    public static function szamjegyek(?string $ertek): string
    {
        return preg_replace('/\D/', '', (string) $ertek) ?? '';
    }

    /** `12345678-2-42` alak, ha 11 jegyű; egyébként az eredeti, trimmelve. */
    public static function formaz(?string $ertek): ?string
    {
        if ($ertek === null || trim($ertek) === '') {
            return null;
        }

        $sz = self::szamjegyek($ertek);

        if (strlen($sz) === 11) {
            return substr($sz, 0, 8).'-'.substr($sz, 8, 1).'-'.substr($sz, 9, 2);
        }

        return trim($ertek);
    }

    /** A törzsszám (első 8 jegy) azonosítja az adóalanyt: az ÁFA-kód és a megyekód változhat, ez nem. */
    public static function torzsszam(?string $ertek): ?string
    {
        $sz = self::szamjegyek($ertek);

        return strlen($sz) >= 8 ? substr($sz, 0, 8) : null;
    }

    /**
     * Magyar adószámként érvényes-e. 8 jegy esetén csak a törzsszámot nézzük,
     * 11 jegy esetén az ÁFA-kódot is.
     */
    public static function ervenyes(?string $ertek): bool
    {
        $sz = self::szamjegyek($ertek);

        if (strlen($sz) !== 8 && strlen($sz) !== 11) {
            return false;
        }

        if (! self::ellenorzoSzamjegyStimmel(substr($sz, 0, 8))) {
            return false;
        }

        if (strlen($sz) === 11) {
            $afaKod = (int) $sz[8];
            if ($afaKod < 1 || $afaKod > 5) {
                return false;
            }
        }

        return true;
    }

    /**
     * Csak azt utasítjuk el, ami biztosan rossz: a magyarnak látszó (8 vagy 11
     * jegyű, csupa számjegy) értéket ellenőrizzük, minden mást átengedünk.
     */
    public static function biztosanRossz(?string $ertek): bool
    {
        if ($ertek === null || trim($ertek) === '') {
            return false;
        }

        $sz = self::szamjegyek($ertek);
        $csakSzamjegy = $sz === preg_replace('/[\s\-]/', '', trim($ertek));

        if (! $csakSzamjegy) {
            return false;
        }

        if (strlen($sz) !== 8 && strlen($sz) !== 11) {
            return false;
        }

        return ! self::ervenyes($sz);
    }

    private static function ellenorzoSzamjegyStimmel(string $torzsszam): bool
    {
        if (! preg_match('/^\d{8}$/', $torzsszam)) {
            return false;
        }

        $osszeg = 0;
        for ($i = 0; $i < 7; $i++) {
            $osszeg += ((int) $torzsszam[$i]) * self::SULYOK[$i];
        }

        $ellenorzo = (10 - ($osszeg % 10)) % 10;

        return $ellenorzo === (int) $torzsszam[7];
    }
}
