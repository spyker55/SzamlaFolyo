<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy kiszámlázott adag túlhasználat.
 *
 * Ez a bizonyíték, hogy a kereten felüli darabokat már terheltük — ebből
 * derül ki, mennyi van még hátra ebben az időszakban. A Stripe tételazonosító
 * azért van eltárolva, hogy egy vitatott számlasoron vissza lehessen keresni,
 * melyik terhelésünk az.
 */
class OverageCharge extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'credits' => 'integer',
        ];
    }
}
