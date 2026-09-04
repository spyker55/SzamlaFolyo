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
    ],
];
