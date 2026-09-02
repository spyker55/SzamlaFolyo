<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * CSV a magyar Excelnek.
 *
 * Pontosvessző, tizedesvessző, UTF-8 BOM és CRLF — ez az a négy dolog, amitől
 * a magyar Excel számként nyitja meg a számokat és nem tolja egy oszlopba az
 * egész sort.
 */
final class CsvIro
{
    /** @param  iterable<array<string, string|float|null>>  $sorok */
    public static function ir(iterable $sorok): string
    {
        $ki = "\u{FEFF}"; // BOM: enélkül az Excel latin-1-ként olvassa az ékezeteket
        $ki .= implode(';', array_map(self::szoveg(...), array_values(Oszlopok::FEJLECEK)))."\r\n";

        foreach ($sorok as $sor) {
            $cellak = [];

            foreach (array_keys(Oszlopok::FEJLECEK) as $kulcs) {
                $ertek = $sor[$kulcs] ?? null;

                $cellak[] = in_array($kulcs, Oszlopok::SZAM_OSZLOPOK, true)
                    ? self::szam($ertek)
                    : self::szoveg($ertek === null ? '' : (string) $ertek);
            }

            $ki .= implode(';', $cellak)."\r\n";
        }

        return $ki;
    }

    private static function szam(mixed $ertek): string
    {
        if ($ertek === null || $ertek === '') {
            return '';
        }

        // Tizedesvessző, csoportosítás nélkül: a magyar Excel így olvassa
        // számként. A számoszlopot **nem** védjük formula-injekció ellen —
        // ott a sztornó mínusza szöveggé fordulna.
        return number_format((float) $ertek, 2, ',', '');
    }

    /**
     * A szöveges cellák formula-injekció ellen védve. A partner nevét egy
     * modell olvasta ki egy PDF-ből, tehát a tartalom idegen eredetű: ha
     * `=`-lel kezdődik, az Excel képletként futtatná.
     */
    private static function szoveg(string $ertek): string
    {
        if ($ertek !== '' && str_contains("=+-@\t\r", $ertek[0])) {
            $ertek = "'".$ertek;
        }

        return '"'.str_replace('"', '""', $ertek).'"';
    }
}
