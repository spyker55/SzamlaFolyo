<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hány kreditet fogyaszt egy irat.
 *
 * A vevő „dokumentumot" vásárol, a mi költségünk viszont oldalarányos: egy
 * nyolcvan oldalas köteg ugyanúgy egy feltöltés, de nem ugyanannyi
 * modellhívásnyi munka, mint egy egyoldalas nyugta. Ha ezt nem mérjük, a nagy
 * csomag margója pont a legnagyobb ügyfeleken tűnik el.
 *
 * A szabály azért ilyen egyszerű, mert **ki kell írni a felületre**: az első
 * `oldal_per_kredit` oldal egy dokumentum, utána minden megkezdett ennyi oldal
 * még egy. Egy normál számla vagy nyugta (1–3 oldal) így biztosan egy marad —
 * a fair-use szabály nem érintheti a hétköznapi használatot, különben nem
 * fair-use, hanem rejtett áremelés.
 *
 * Az oldalszám ismerete nem feltétel: amiről nem tudjuk (kép, XML, sérült
 * PDF), az egy kredit. Bizonytalanságból nem számlázunk többet.
 */
final class Kredit
{
    public static function oldalakbol(?int $oldalszam): int
    {
        $hatar = self::hatar();

        if ($oldalszam === null || $oldalszam <= $hatar) {
            return 1;
        }

        return (int) ceil($oldalszam / $hatar);
    }

    /** A szabály egy mondatban, ahogy a felületen is áll. */
    public static function szabaly(): string
    {
        return sprintf(
            'Az első %d oldal egy dokumentum; e fölött minden megkezdett %d oldal még egy.',
            self::hatar(),
            self::hatar(),
        );
    }

    public static function hatar(): int
    {
        return max(1, (int) config('szamlafolyo.kredit.oldal_per_kredit', 5));
    }
}
