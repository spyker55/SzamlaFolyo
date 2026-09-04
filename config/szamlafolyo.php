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
        // Pontosan egy Start-hónap, ingyen. Húsz dokumentum volt itt korábban,
        // abból viszont egy könyvelőiroda egy óra alatt kifut, és úgy sosem jut
        // el a termék lényegéhez: a kötegig, az ellenőrzésig, a havi exportig.
        //
        // A szűkítés indoka („az ingyen keret AI-költséget generál") ezen a
        // modellen nem áll: egy kiolvasás nagyságrendileg fillér, nem forint.
        // A visszaélés ellen sem ez véd, hanem hogy a próba céghez kötött, és
        // a fájlok hét nap után törlődnek.
        //
        // A kettő **vagy** kapcsolatban van: amelyik előbb elfogy, az zárja le
        // a próbát. Ezt a `Kvota` így is számolja.
        'documents' => 50,
        // Három fő a próbában. A költséget a darabszám fogja meg, nem a
        // fejszám — egy könyvelőiroda pedig ne egyedül kényszerüljön
        // kipróbálni a terméket.
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
    | Túlhasználat
    |---------------------------------------------------------------------------
    | A keret fölötti feldolgozásnak **felső határa** van, forintban. Enélkül
    | egy véletlenül feltöltött négyezer oldalas archívum harmincezer forintos
    | meglepetés a következő számlán — és a kapcsoló, ami ezt lehetővé teszi,
    | nem vállalható ilyen fék nélkül.
    |
    | Ez csak a kezdőérték: a bekapcsoláskor kerül a cégre, utána a tulajdonos
    | átírhatja, vagy kiürítheti (üres = nincs plafon, a saját felelősségére).
    */

    'tulhasznalat' => [
        'alap_plafon_ft' => 10000,
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
    | **Éves fizetés nincs, és ez szándékos.** A keret a Stripe számlázási
    | ciklusára szól (`Kvota::idoszak()`), egy éves előfizetésnél viszont ez a
    | ciklus tizenkét hónap — az éves ár így nem havi keretet adna, hanem évit,
    | vagyis egytizenketted terméket. Havi keret + éves számlázás csak külön
    | forgó ablakkal működne; amíg az nincs meg, csak havi árat adunk el.
    |
    | Az `extra_ft` a keret fölötti darabár, és **mindig drágább**, mint az
    | adott csomag saját darabára (ár ÷ darabszám: 39,8 / 24,95 / 19,98) —
    | különben azt tanítanánk, hogy megéri a kis csomagban maradni. Csak akkor
    | lép életbe, ha a cég külön engedélyezte a túlhasználatot
    | (`companies.overage_enabled`), különben a keret megállít.
    */

    'plans' => [
        'kicsi' => [
            'nev' => 'Start',
            'documents' => 50,
            // Két fő, nem egy. A költségünk oldalarányos, a fejszám nem kerül
            // semmibe — egy egyszemélyes cégnél viszont majdnem mindig van egy
            // könyvelő is, aki be akar nézni. Az egyfős keret nem bevételt hoz,
            // hanem közös jelszót, ami nekünk biztonsági kockázat.
            'users' => 2,
            'ar_havi' => 1990,
            'extra_ft' => 49,
            'price_id' => env('STRIPE_PRICE_KICSI'),
            'price_id_extra' => env('STRIPE_PRICE_KICSI_EXTRA'),
        ],
        'kozepes' => [
            'nev' => 'Flow',
            'documents' => 200,
            'users' => 5,
            'ar_havi' => 4990,
            'extra_ft' => 29,
            'price_id' => env('STRIPE_PRICE_KOZEPES'),
            'price_id_extra' => env('STRIPE_PRICE_KOZEPES_EXTRA'),
        ],
        'nagy' => [
            'nev' => 'Pro',
            'documents' => 500,
            'users' => 10,
            'ar_havi' => 9990,
            'extra_ft' => 24,
            'price_id' => env('STRIPE_PRICE_NAGY'),
            'price_id_extra' => env('STRIPE_PRICE_NAGY_EXTRA'),
        ],
    ],
];
