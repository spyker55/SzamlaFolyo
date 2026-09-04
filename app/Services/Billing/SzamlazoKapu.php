<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;

/**
 * Amit a túlhasználat elszámolása a számlázótól kér — és semmi több.
 *
 * A `StripeSzolgaltatas` sokat tud (checkout, ügyfélportál, előfizetés-átvétel),
 * a `Tulhasznalat`-nak viszont ebből egyetlen művelet kell. Ez az elnevezett
 * felület kimondja, melyik az, és egyben ez az a varrat, ahol a tesztben a
 * külvilág lecserélhető: pénzt mozgató kódot nem szabad úgy hagyni, hogy
 * csak éles Stripe-kapcsolattal futtatható.
 */
interface SzamlazoKapu
{
    /**
     * A keret fölötti darabok felvitele a cég következő számlájára.
     *
     * @return string a létrejött tétel azonosítója
     */
    public function extraTetel(Company $ceg, string $email, string $priceId, int $darab): string;
}
