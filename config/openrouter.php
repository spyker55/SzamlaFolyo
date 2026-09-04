<?php

declare(strict_types=1);

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),

    /*
     * Az alapértelmezett kiolvasó modell.
     *
     * Két valódi magyar számlán mérve a Gemini 3.1 Flash Lite ugyanazokat a
     * mezőket adta, mint a Claude Sonnet 5 — az egyetlen eltérésnél (a vevő
     * nevének ékezete) éppen az olcsóbb írta helyesen —, miközben 15×
     * olcsóbb és 2,5× gyorsabb volt. A mérést a `kiolvasas:proba --modell`
     * bármikor megismétli.
     *
     * Amit tudni kell róla: a magabiztosságát nem érdemes komolyan venni.
     * Egy jól kiolvasott mezőre adott 0,5-öt, a Sonnet pedig 0,95-öt az
     * egyetlen tévedésére — a megbízható jel a validátoroké.
     */
    'model' => env('OPENROUTER_MODEL', 'google/gemini-3.1-flash-lite'),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 90),
];
