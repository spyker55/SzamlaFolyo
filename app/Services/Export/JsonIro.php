<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * JSON gépi feldolgozásra: a szám szám marad, a dátum ISO alakú, a hiányzó
 * érték null — nem üres sztring.
 *
 * Egyedül itt fér el az ÁFA-bontás **teljes** alakja. A táblázatos formátumok
 * kulcsonkénti oszlopokra lapítják (27/18/5/0/egyéb), mert egy sor egy
 * bizonylat; a beágyazott lista viszont megőrzi a kategóriakódot is, ami
 * nélkül egy nulla százalékos sor értelmezhetetlen.
 */
final class JsonIro
{
    /** @param  iterable<array<string, string|float|null>>  $sorok */
    public static function ir(iterable $sorok, array $meta = []): string
    {
        $tetelek = [];

        foreach ($sorok as $sor) {
            $tetel = [];

            foreach (array_keys(Oszlopok::FEJLECEK) as $kulcs) {
                $ertek = $sor[$kulcs] ?? null;

                if (in_array($kulcs, Oszlopok::SZAM_OSZLOPOK, true)) {
                    $tetel[$kulcs] = $ertek === null || $ertek === '' ? null : round((float) $ertek, 2);

                    continue;
                }

                $tetel[$kulcs] = $ertek === '' ? null : $ertek;
            }

            if (isset($sor['afa_bontas']) && is_array($sor['afa_bontas'])) {
                $tetel['afa_bontas'] = $sor['afa_bontas'];
            }

            $tetelek[] = $tetel;
        }

        return json_encode(
            $meta + ['tetelek' => $tetelek],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ) ?: '{}';
    }
}
