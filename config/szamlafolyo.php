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
            // Önálló e-számla (UBL, Factur-X/ZUGFeRD CII). A tartalomból
            // megállapított típus ezekre `text/xml`, de a küldő oldal néha
            // `application/xml`-t mond — mindkettőt elfogadjuk.
            'text/xml' => 'xml',
            'application/xml' => 'xml',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Nyitottság
    |---------------------------------------------------------------------------
    | Amíg az oldal fejlesztés alatt áll, a nyilvános regisztráció zárva van, és
    | a belépés előtti képernyőkön figyelmeztetés fogadja a látogatót.
    |
    | A kollégák meghívása **nem** ezen múlik: azt a Beállítások képernyőn a
    | cég tulajdonosa intézi, belépve — az nem nyilvános regisztráció.
    |
    | Mindkettő .env-ből átbillenthető, újratelepítés nélkül:
    |   REGISZTRACIO_NYITVA=true
    |   FEJLESZTES_ALATT=false
    */

    'regisztracio_nyitva' => (bool) env('REGISZTRACIO_NYITVA', false),
    'fejlesztes_alatt' => (bool) env('FEJLESZTES_ALATT', true),
    'kapcsolat_email' => env('KAPCSOLAT_EMAIL', 'info@szamlafolyo.hu'),

    /*
    |---------------------------------------------------------------------------
    | Kiolvasás
    |---------------------------------------------------------------------------
    | A `review_threshold` **fölött** a mező jelöletlen marad (a kiemelés a
    | bajt jelöli, nem a rendben lévőt), a `warn_threshold`-ig piros, a kettő
    | között sárga. A bukott validátor mindig a piros sávba húz.
    |
    | Maga a határérték az óvatosabb sávba esik: a modellek kerek számokat
    | mondanak, és a határra eső 0,85 nem jótállás.
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
        // Az ingyen keret valódi pénz: minden darab egy modellhívás. Húsz
        // bizonylat bőven elég annak eldöntésére, hogy jó-e a termék, és épp
        // kevés ahhoz, hogy valaki ingyen könyveljen belőle egy hónapot.
        'documents' => 20,
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
