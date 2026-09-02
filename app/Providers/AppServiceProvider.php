<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Berlo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Berlo::class);
    }

    public function boot(): void
    {
        // Élesben a tárhely a TLS-t a proxy előtt zárja: enélkül a generált
        // linkek http-re mutatnának.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Fejlesztés közben a néma hibák kerülnek a legtöbbe: a lusta betöltés
        // és a nem létező attribútum írása is hangos legyen.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
