<?php

declare(strict_types=1);

return [
    // 'catchall': <token>@domain  ·  'plus': bekuldes+<token>@domain
    'mode' => env('INBOX_MODE', 'catchall'),
    'domain' => env('INBOX_DOMAIN', 'bekuldes.szamlafolyo.hu'),
    'plus_address' => env('INBOX_PLUS_ADDRESS'),

    'imap' => [
        'host' => env('IMAP_HOST'),
        'port' => (int) env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => true,
        'username' => env('IMAP_USERNAME'),
        'password' => env('IMAP_PASSWORD'),
        'protocol' => 'imap',
        'folder' => env('IMAP_FOLDER', 'INBOX'),
        'processed_folder' => env('IMAP_PROCESSED_FOLDER', 'Feldolgozott'),
        // A besorolatlan levél külön mappába megy: a feldolgozottak közé
        // keverve pont az veszne el, amit keresni kell.
        'unmatched_folder' => env('IMAP_UNMATCHED_FOLDER', 'Besorolatlan'),

        // Megőrzési idő a postafiókban. Enélkül a feldolgozott levél a
        // mellékletével együtt örökre ott marad: betelik a tárhely, és
        // hazuggá válik a `fajl:selejtez` ígérete is, hiszen az eredeti
        // számla egy másolata a levélben túléli a fájl törlését.
        //
        // A feldolgozottakra a cégek fájl-megőrzési plafonja vonatkozik
        // (`Company::MEGORZES_MAX_NAP`) — ott ugyanaz az irat fekszik, ami
        // már bent van az alkalmazásban.
        'keep_days' => (int) env('INBOX_KEEP_DAYS', 7),

        // A besorolatlan levélből **nem lett** irat: ez az egyetlen példány,
        // és emberi ránézést kér. Ezért kap hosszabb türelmi időt — de nem
        // végtelent, mert a beküldési cím publikus, és a spam is ide gyűlik.
        'unmatched_keep_days' => (int) env('INBOX_UNMATCHED_KEEP_DAYS', 14),
    ],
];
