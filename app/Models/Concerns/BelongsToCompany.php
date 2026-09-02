<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Berlo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        // A `company_id`-t nem a hívó tölti ki. Ha kifelejtené, a sor a
        // semmibe kerülne, és a bérlő-elkülönítés csendben kilyukadna.
        static::creating(function ($model): void {
            if ($model->company_id === null) {
                $model->company_id = app(Berlo::class)->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * URL-ből érkező azonosító feloldása.
     *
     * A globális scope önmagában itt **nem elég**: a route model binding a
     * `web` middleware-csoportban fut, még mielőtt a bérlőt beállítanánk —
     * így a scope üresen áll, és bárki lekérhetné más cég sorát az
     * azonosítójával. Ezért itt magunk oldjuk fel a céget, a bejelentkezett
     * felhasználóból, és nem hagyatkozunk a middleware sorrendjére.
     */
    public function resolveRouteBinding($ertek, $mezo = null)
    {
        $ceg = app(Berlo::class)->ceg() ?? auth()->user()?->ceg();

        if ($ceg === null) {
            return null;
        }

        return $this->newQuery()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $ceg->id)
            ->where($mezo ?? $this->getRouteKeyName(), $ertek)
            ->first();
    }
}
