<?php

declare(strict_types=1);

/*
 * A validációs üzenetek magyarul.
 *
 * # Miért van ez a fájl
 *
 * Egy éles hibát javít, nem kényelmi fordítás. Az `APP_LOCALE=hu`, a
 * `APP_FALLBACK_LOCALE` szintén `hu`, `lang/hu/` viszont **nem létezett** — a
 * Laravel így egyetlen üzenetkulcsot sem tudott feloldani, és a nyers kulcsot
 * írta ki a képernyőre: az üres névvel elküldött regisztráció alatt szó
 * szerint az állt, hogy `validation.required`.
 *
 * # Miért nincs bennük `:attribute`
 *
 * Mert magyarul nem jön ki. „A(z) e-mail cím nem érvényes e-mail cím" — a
 * névelő a következő szótól függ, a ragozás a mezőnévtől, és a sablon
 * egyikről sem tud. Az angol mondatszerkezet ezt elbírja, a magyar nem.
 *
 * Megtehetjük, mert **minden hiba a saját mezője alatt jelenik meg** (a
 * képernyők `@error('mezo')`-vel írják ki, összefoglaló hibalista nincs): a
 * mezőt a fölötte álló címke nevezi meg, az üzenetnek elég megmondania, mi a
 * baj. A hívók `attributes:` listája ettől még a helyén marad — ha egyszer egy
 * üzenetbe mégis kell a mezőnév, ott van hozzá.
 */

return [
    'accepted' => 'Ezt el kell fogadni a folytatáshoz.',
    'array' => 'Ez az érték csak lista lehet.',
    'boolean' => 'Ez az érték csak igen vagy nem lehet.',
    'confirmed' => 'A két mező nem egyezik.',
    'date' => 'Ez nem érvényes dátum.',
    'different' => 'A két érték nem lehet ugyanaz.',
    'email' => 'Ez nem érvényes e-mail cím.',
    'exists' => 'Ilyet nem találunk.',
    'file' => 'Ide fájlt kell feltölteni.',
    'image' => 'Ide képet kell feltölteni.',
    'in' => 'Ez az érték nem választható.',
    'integer' => 'Csak egész szám lehet.',
    'mimes' => 'Csak :values típusú fájl tölthető fel.',
    'numeric' => 'Csak szám lehet.',
    'required' => 'Ezt a mezőt kötelező kitölteni.',
    'same' => 'A két érték nem egyezik.',
    'string' => 'Csak szöveg lehet.',
    'unique' => 'Ez már foglalt.',
    'uploaded' => 'A feltöltés nem sikerült.',
    'url' => 'Ez nem érvényes webcím.',

    'between' => [
        'array' => ':min és :max közötti számú elem lehet.',
        'file' => ':min és :max kilobájt közötti méretű lehet.',
        'numeric' => ':min és :max közötti érték lehet.',
        'string' => ':min és :max karakter közötti hosszú lehet.',
    ],
    'max' => [
        'array' => 'Legfeljebb :max elem lehet.',
        'file' => 'Legfeljebb :max kilobájt lehet.',
        'numeric' => 'Legfeljebb :max lehet.',
        'string' => 'Legfeljebb :max karakter lehet.',
    ],
    'min' => [
        'array' => 'Legalább :min elem kell.',
        'file' => 'Legalább :min kilobájt kell.',
        'numeric' => 'Legalább :min legyen.',
        'string' => 'Legalább :min karakter legyen.',
    ],

    /* A `Password::defaults()` saját kulcsai. */
    'password' => [
        'letters' => 'Legyen benne legalább egy betű.',
        'mixed' => 'Legyen benne kis- és nagybetű is.',
        'numbers' => 'Legyen benne legalább egy szám.',
        'symbols' => 'Legyen benne legalább egy írásjel.',
        'uncompromised' => 'Ez a jelszó szerepel egy nyilvános adatszivárgásban. Válassz másikat.',
    ],

    'custom' => [],
    'attributes' => [],
];
