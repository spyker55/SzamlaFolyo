<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Berlo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Bérlő-elkülönítés egyetlen helyen. Amíg van kiválasztott cég, minden
 * lekérdezés arra szűkül; ha valahol tudatosan cégek fölött kell dolgozni
 * (cron), azt a hívónak ki kell mondania: `withoutGlobalScope(CompanyScope::class)`.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $berlo = app(Berlo::class);

        if ($berlo->van()) {
            $builder->where($model->qualifyColumn('company_id'), $berlo->id());
        }
    }
}
