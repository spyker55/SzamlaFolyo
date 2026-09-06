<?php

declare(strict_types=1);

namespace App\Services\Account;

/**
 * Amit a fióktörlés a számlázótól kér — és semmi több.
 *
 * Ugyanaz a varrat, mint a `SzamlazoKapu`: a törlés egyetlen kifelé ható
 * lépése a lemondás, és ezt kell tudni tesztből lecserélni. Egy olyan
 * műveletet, ami után pénz folyik tovább vagy éppen nem folyik, nem szabad
 * úgy hagyni, hogy csak éles Stripe-kapcsolattal futtatható.
 */
interface ElofizetesKapu
{
    /** Beállított-e egyáltalán a számlázó ezen a példányon. */
    public function beallitva(): bool;

    /**
     * Az előfizetés azonnali lemondása.
     *
     * A megvalósítás akkor is sikerrel tér vissza, ha ilyen élő előfizetés
     * már nincs: a lemondás célja ilyenkor teljesült. Minden más hibát
     * viszont **el kell dobnia** — a hívó ebből tudja meg, hogy nem szabad
     * törölnie, mert futva maradna egy fizetős előfizetés.
     */
    public function elofizetestLemond(string $id): void;
}
