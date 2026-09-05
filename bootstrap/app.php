<?php

use App\Http\Middleware\BiztonsagiFejlecek;
use App\Http\Middleware\SetCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ceg' => SetCompany::class,
        ]);

        // Minden válaszra, a fájlkiszolgálásra és a Livewire-kérésekre is.
        $middleware->append(BiztonsagiFejlecek::class);

        // A Laravel alapból a `login` nevű útvonalra küldi a bejelentkezés
        // nélküli látogatót, a mienket viszont `bejelentkezes`-nek hívják —
        // enélkül a főoldal 500-as hibát adna mindenkinek, aki még nem lépett be.
        $middleware->redirectGuestsTo(fn () => route('bejelentkezes'));
        $middleware->redirectUsersTo(fn () => route('beerkezo'));

        // A Stripe a saját aláírásával hitelesíti magát, CSRF-tokent nem tud küldeni.
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
