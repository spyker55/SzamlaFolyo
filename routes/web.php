<?php

declare(strict_types=1);

use App\Http\Controllers\CegValtasController;
use App\Http\Controllers\DokumentumFajlController;
use App\Http\Controllers\ExportLetoltesController;
use App\Http\Controllers\KijelentkezesController;
use App\Http\Controllers\StripeWebhookController;
use App\Livewire\App\Archivum;
use App\Livewire\App\Beallitasok;
use App\Livewire\App\Beerkezo;
use App\Livewire\App\CegLetrehozas;
use App\Livewire\App\Ellenorzes;
use App\Livewire\App\ExportKepernyo;
use App\Livewire\App\Tetelek;
use App\Livewire\Auth\Bejelentkezes;
use App\Livewire\Auth\ElfelejtettJelszo;
use App\Livewire\Auth\JelszoBeallitas;
use App\Livewire\Auth\Regisztracio;
use Illuminate\Support\Facades\Route;

/*
 * A főoldal. Vendégnek a nyilvános oldal, belépett felhasználónak a Beérkező —
 * aki már dolgozik, annak nincs dolga a marketinggel, és a régi viselkedés
 * (mindenkit átirányítani) azt jelentette, hogy a be nem lépett látogató
 * egyenesen a bejelentkező űrlapon kötött ki, anélkül hogy megtudta volna,
 * mit csinál az oldal.
 */
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('beerkezo')
        : view('nyitolap');
})->name('kezdolap');

Route::middleware('guest')->group(function (): void {
    Route::get('/bejelentkezes', Bejelentkezes::class)->name('bejelentkezes');
    Route::get('/regisztracio', Regisztracio::class)->name('regisztracio');
    Route::get('/elfelejtett-jelszo', ElfelejtettJelszo::class)->name('password.request');
    Route::get('/jelszo-beallitas/{token}', JelszoBeallitas::class)->name('password.reset');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/kijelentkezes', KijelentkezesController::class)->name('kijelentkezes');

    // Cég nélkül csak ez az egy képernyő érhető el; több cég esetén innen
    // nyílik a következő is.
    Route::get('/ceg-letrehozas', CegLetrehozas::class)->name('ceg.letrehozas');

    // A cégváltás nem igényel aktív bérlőt — épp azt állítja be.
    Route::post('/ceg-valtas', CegValtasController::class)->name('ceg.valtas');

    Route::middleware('ceg')->group(function (): void {
        Route::get('/beerkezo', Beerkezo::class)->name('beerkezo');
        Route::get('/ellenorzes/{dokumentum}', Ellenorzes::class)->name('ellenorzes');
        Route::get('/tetelek', Tetelek::class)->name('tetelek');
        Route::get('/export', ExportKepernyo::class)->name('export');
        Route::get('/archivum', Archivum::class)->name('archivum');
        Route::get('/beallitasok', Beallitasok::class)->name('beallitasok');

        // A feltöltött fájlok a webgyökéren kívül vannak; ez az egyetlen út hozzájuk.
        Route::get('/dokumentum/{dokumentum}/fajl', DokumentumFajlController::class)->name('dokumentum.fajl');
        Route::get('/export/{export}/letoltes', ExportLetoltesController::class)->name('export.letoltes');
    });
});

// A Stripe a saját aláírásával hitelesíti magát; se munkamenet, se CSRF.
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
