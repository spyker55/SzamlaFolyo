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
     * A magabiztosság **modellfüggő**, és ezt korábban elnéztük. A Flash
     * Lite-é használhatatlan: 0,5 egy jól kiolvasott mezőre, majd 1,00 három
     * különböző kitalált névre. A 3.8 Flash ugyanezen az iraton 0,70-et adott
     * a szállító nevére — a lap legalacsonyabb értékét, pontosan az egyetlen
     * rossz mezőre —, a többire 0,95–0,99-et. Ugyanez a modell a
     * `nehezen_olvashato` zászlót is helyesen beállította, amit a Lite nem.
     *
     * Ettől még a validátor a megbízhatóbb jel, és az összevonás csak lefelé
     * húzhat: egy mérés nem kalibráció.
     */
    'model' => env('OPENROUTER_MODEL', 'google/gemini-3.8-flash'),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 90),
];
