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

    /**
     * 'nincs_adat' | 'biztos' | 'bizonytalan' | 'gyanus' — ez a négy állapot
     * az ellenőrző képernyőn.
     *
     * A hiányzó magabiztosság **nem** ugyanaz, mint a magas: az egyikért a
     * modell jótállt, a másikról semmit nem tudunk. Korábban a kettő egyformán
     * festett, és így egy néma modell ugyanolyan megnyugtatónak látszott, mint
     * egy magabiztos — épp azt takarva el, amit tudni kellene.
     */
    public static function sav(?float $pont): string
    {
        if ($pont === null) {
            return 'nincs_adat';
        }

        // A határérték a **óvatosabb** sávba esik, ezért `<=` és nem `<`.
        //
        // Nem elméleti finomság: a modellek kerek számokat mondanak, és a 0,85
        // az egyik kedvencük. Egy kézzel írott számlán a 3.8 Flash pontosan
        // 0,85-öt adott a szállító nevére — a lap legalacsonyabb értékét, és az
        // egyetlen rossz mezőt —, ami szigorú `<`-nál jelöletlen maradt volna.
        // Egy 85%-os állítás nem jótállás: hetente egyszer téved.
        if ($pont <= (float) config('szamlafolyo.extraction.warn_threshold')) {
            return 'gyanus';
        }

        if ($pont <= (float) config('szamlafolyo.extraction.review_threshold')) {
            return 'bizonytalan';
        }

        return 'biztos';
    }
}
