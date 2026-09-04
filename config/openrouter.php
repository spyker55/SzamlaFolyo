<?php

declare(strict_types=1);

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),

    /*
     * Az alapértelmezett kiolvasó modell.
     *
     * Két **gépi nyomtatású** számlán a Gemini 3.1 Flash Lite ugyanazokat a
     * mezőket adta, mint a Claude Sonnet 5, 15× olcsóbban — ezért lett egy
     * ideig ez az alapértelmezés. Egy **kézzel írott** számlán viszont
     * megbukott: háromszor kiolvasva három különböző, kitalált szállítónevet
     * adott („Süli János", „Sién János", végül „Süni Fúró" — ez utóbbit
     * láthatóan a tételsor szövegéből, a „Betonfuratok készítése"-ből
     * gyúrta), és mind a háromszor 1,00 magabiztossággal.
     *
     * A Pro drága és lassú (a mérésben ötször lassabb a Sonnetnél, drágább
     * is), ezért a köztes fok az alapértelmezés.
     *
     * Amit mindegyik modellről tudni kell: a magabiztosságát nem érdemes
     * komolyan venni. Öt mérésből ötször tévedett — hol lefelé egy jó mezőn,
     * hol 1,00-t adva egy kitalált névre. A megbízható jel a validátoroké.
     */
    'model' => env('OPENROUTER_MODEL', 'google/gemini-3.8-flash'),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 90),
];
