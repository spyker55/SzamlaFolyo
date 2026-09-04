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
        //
        // A kettő **vagy** kapcsolatban van: amelyik előbb elfogy, az zárja le
        // a próbát. Ezt a `Kvota` így is számolja.
        'documents' => 20,
        // Próbaidőben a Flow csomag felhasználószáma jár. A költséget a
        // darabszám fogja meg, nem a fejszám — egy könyvelőiroda pedig ne
        // egyedül kényszerüljön kipróbálni a terméket.
        'users' => 3,
    ],

    /*
    |---------------------------------------------------------------------------
    | Kredit
    |---------------------------------------------------------------------------
    | A vevő „dokumentumot" vásárol, a költségünk viszont oldalarányos: egy
    | nyolcvan oldalas köteg nem kerülhet ugyanannyiba, mint egy egyoldalas
    | nyugta. A belső mérés ezért oldalalapú.
    |
    | A szabály szándékosan egyszerű, mert ki kell írni a felületre: az első
    | `oldal_per_kredit` oldal egy dokumentum, utána minden megkezdett ennyi
    | oldal még egy. Egy normál számla (1–3 oldal) így biztosan egy marad.
    */

    'kredit' => [
        'oldal_per_kredit' => 5,
    ],

    /*
    |---------------------------------------------------------------------------
    | Csomagok
    |---------------------------------------------------------------------------
    | Ez az egyetlen hely, ahol a csomagok számai állnak: a nyitólap árlistája,
    | a Beállítások képernyő és a keretszámolás mind innen olvas. Egy marketing-
    | szövegbe kézzel beírt szám előbb-utóbb elcsúszik attól, amit a rendszer
    | valóban ad — az pedig az árlistán szerződéses ígéret.
    |
    | Az éves ár a „12 hónap 10 havi díjért" logika. Az `extra_ft` a keret
    | fölötti darabár; csak akkor lép életbe, ha a cég **külön engedélyezte** a
    | túlhasználatot (`companies.overage_enabled`), különben a keret megállít.
    */

    'plans' => [
        'kicsi' => [
            'nev' => 'Start',
            'documents' => 50,
            'users' => 1,
            'ar_havi' => 1990,
            'ar_evi' => 19900,
            'extra_ft' => 39,
            'price_id' => env('STRIPE_PRICE_KICSI'),
            'price_id_evi' => env('STRIPE_PRICE_KICSI_EVI'),
            'price_id_extra' => env('STRIPE_PRICE_KICSI_EXTRA'),
        ],
        'kozepes' => [
            'nev' => 'Flow',
            'documents' => 200,
            'users' => 3,
            'ar_havi' => 4990,
            'ar_evi' => 49900,
            'extra_ft' => 29,
            'price_id' => env('STRIPE_PRICE_KOZEPES'),
            'price_id_evi' => env('STRIPE_PRICE_KOZEPES_EVI'),
            'price_id_extra' => env('STRIPE_PRICE_KOZEPES_EXTRA'),
        ],
        'nagy' => [
            'nev' => 'Pro',
            'documents' => 1000,
            'users' => 10,
            'ar_havi' => 14990,
            'ar_evi' => 149900,
            'extra_ft' => 19,
            'price_id' => env('STRIPE_PRICE_NAGY'),
            'price_id_evi' => env('STRIPE_PRICE_NAGY_EVI'),
            'price_id_extra' => env('STRIPE_PRICE_NAGY_EXTRA'),
        ],
    ],
];
