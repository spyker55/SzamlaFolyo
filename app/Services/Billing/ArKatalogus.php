<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Az árak visszaolvasása a számlázótól.
 *
 * Külön felület a `SzamlazoKapu` mellett, mert más a dolga: az pénzt mozgat,
 * ez csak kérdez. A `config/szamlafolyo.php`-ban álló számok a felületen
 * jelennek meg, a terhelés viszont a Stripe áraiból lesz — a kettő között
 * semmi nem garantálja az egyezést, és ha elcsúsznak, azt a vevő a számláján
 * tudja meg. Ez a felület teszi ellenőrizhetővé a kettőt egymáshoz képest.
 */
interface ArKatalogus
{
    /** `null`, ha a számlázó nem ismeri ezt az árazonosítót. */
    public function ar(string $priceId): ?Ar;
}
