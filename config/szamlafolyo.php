<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Feltöltés
    |---------------------------------------------------------------------------
    | A méret- és típuskorlát egy helyen áll: a böngészőből érkező feltöltés és
    | az e-mailes beérkeztetés ugyanezt olvassa, hogy a két út ne csússzon szét.
    */

    'upload' => [
        'max_bytes' => 20 * 1024 * 1024,
        'mime_types' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Kiolvasás
    |---------------------------------------------------------------------------
    | A `review_threshold` fölött zöld a mező, a `warn_threshold` alatt piros,
    | a kettő között sárga. A bukott validátor mindig a piros sávba húz.
    */

    'extraction' => [
        'review_threshold' => 0.85,
        'warn_threshold' => 0.50,
        'max_attempts' => 3,
        'claim_timeout_minutes' => 5,
    ],

    /*
    |---------------------------------------------------------------------------
    | Csomagok
    |---------------------------------------------------------------------------
    | A darabkeret a Stripe ár-azonosítóhoz tartozik, nem a felhasználóhoz. A
    | próbaidő Stripe nélkül fut: kártya nélkül lehet kipróbálni.
    */

    'trial' => [
        'days' => 14,
        'documents' => 50,
    ],

    'plans' => [
        'kicsi' => [
            'nev' => 'Kicsi',
            'documents' => 50,
            'price_id' => env('STRIPE_PRICE_KICSI'),
        ],
        'kozepes' => [
            'nev' => 'Közepes',
            'documents' => 200,
            'price_id' => env('STRIPE_PRICE_KOZEPES'),
        ],
        'nagy' => [
            'nev' => 'Nagy',
            'documents' => 1000,
            'price_id' => env('STRIPE_PRICE_NAGY'),
        ],
    ],
];
