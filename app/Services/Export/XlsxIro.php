<?php

declare(strict_types=1);

namespace App\Services\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX az OpenSpout streamelő írójával. Nem PhpSpreadsheet: az az egész
 * munkafüzetet a memóriában tartja, és egy osztott tárhely memórialimitjén ez
 * néhány száz sor után elfogy.
 *
 * A számok **számként** kerülnek a cellába, nem szövegként — különben a
 * könyvelő nem tud velük számolni, és ez az egész export értelme.
 */
final class XlsxIro
{
    /** @param  iterable<array<string, string|float|null>>  $sorok */
    public static function fajlba(iterable $sorok, string $utvonal): void
    {
        $iro = new Writer;
        $iro->openToFile($utvonal);

        $fejlecStilus = (new Style)
            ->setFontBold()
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);

        $iro->addRow(Row::fromValues(array_values(Oszlopok::FEJLECEK), $fejlecStilus));

        foreach ($sorok as $sor) {
            $cellak = [];

            foreach (array_keys(Oszlopok::FEJLECEK) as $kulcs) {
                $ertek = $sor[$kulcs] ?? null;

                if (in_array($kulcs, Oszlopok::SZAM_OSZLOPOK, true)) {
                    $cellak[] = $ertek === null || $ertek === '' ? '' : round((float) $ertek, 2);

                    continue;
                }

                $cellak[] = $ertek === null ? '' : (string) $ertek;
            }

            $iro->addRow(Row::fromValues($cellak));
        }

        $iro->close();
    }
}
