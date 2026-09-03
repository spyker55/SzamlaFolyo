<?php

declare(strict_types=1);

namespace App\Services\Extraction;

/**
 * Két jelből egy szám mezőnként.
 *
 * A modell magáról mondja meg, mennyire biztos — ez hasznos, de rosszul
 * kalibrált. A determinisztikus validátor viszont a papírtól független
 * tényt állapít meg. Ezért az összevonás egyirányú: **a validátor csak
 * lefelé húzhat.** Ha egy mező megbukott az ellenőrzésen, hiába mondja a
 * modell, hogy biztos benne.
 */
final class Konfidencia
{
    /** A bukott validátor ide húzza a mezőt: biztosan a piros sávba. */
    public const BUKAS_PLAFON = 0.3;

    /**
     * @param  array<string, float>  $modellSzerint
     * @param  array<string, string>  $bukottValidatorok
     * @return array{model: array<string, float>, validators: array<string, string>, combined: array<string, float>}
     */
    public static function osszevon(array $modellSzerint, array $bukottValidatorok, array $mezok): array
    {
        $eredmeny = [];

        foreach (Sema::MEZOK as $mezo) {
            $ertek = $mezok[$mezo] ?? null;

            // Az üres mezőnek nincs értelmes magabiztossága: nincs mit
            // ellenőrizni rajta, és nem is szabad pirosnak látszania.
            if ($ertek === null || $ertek === '') {
                continue;
            }

            // Amiről a modell nem nyilatkozott, azt nem tekintjük biztosnak.
            $pont = $modellSzerint[$mezo] ?? 0.5;

            if (isset($bukottValidatorok[$mezo])) {
                $pont = min($pont, self::BUKAS_PLAFON);
            }

            $eredmeny[$mezo] = round($pont, 3);
        }

        // Az ÁFA-bontás nem skalár mező, ezért kimarad a fenti ciklusból — de
        // ugyanúgy van magabiztossága, és ugyanúgy lehúzhatja a validátor.
        if (($mezok['afa_bontas'] ?? null) !== null) {
            $pont = $modellSzerint['afa_bontas'] ?? 0.5;

            if (isset($bukottValidatorok['afa_bontas'])) {
                $pont = min($pont, self::BUKAS_PLAFON);
            }

            $eredmeny['afa_bontas'] = round($pont, 3);
        }

        return [
            'model' => $modellSzerint,
            'validators' => $bukottValidatorok,
            'combined' => $eredmeny,
        ];
    }

    /** 'biztos' | 'bizonytalan' | 'gyanus' — ez a három szín az ellenőrző képernyőn. */
    public static function sav(?float $pont): string
    {
        if ($pont === null) {
            return 'biztos';
        }

        if ($pont < (float) config('szamlafolyo.extraction.warn_threshold')) {
            return 'gyanus';
        }

        if ($pont < (float) config('szamlafolyo.extraction.review_threshold')) {
            return 'bizonytalan';
        }

        return 'biztos';
    }
}
