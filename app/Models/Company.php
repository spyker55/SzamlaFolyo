<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Szerep;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'file_retention_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $ceg): void {
            $ceg->inbox_token ??= self::ujToken();
            $ceg->trial_ends_at ??= now()->addDays((int) config('szamlafolyo.trial.days'));
        });
    }

    /**
     * 64 bitnyi véletlen, kisbetűs hexa. Ez a cím kitalálhatatlan része: aki
     * nem kapta meg, nem tud a cég nevében iratot beküldeni.
     */
    public static function ujToken(): string
    {
        do {
            $token = bin2hex(random_bytes(8));
        } while (self::query()->where('inbox_token', $token)->exists());

        return $token;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    public function szerepe(User $user): ?Szerep
    {
        $pivot = $this->users()->where('users.id', $user->id)->first()?->pivot;

        return $pivot ? Szerep::tryFrom((string) $pivot->role) : null;
    }

    /** A beérkeztető cím, ahogy a felhasználónak megmutatjuk. */
    public function beerkezteoCim(): string
    {
        if (config('inbox.mode') === 'plus') {
            $alap = (string) config('inbox.plus_address');
            [$helyi, $domain] = array_pad(explode('@', $alap, 2), 2, '');

            return sprintf('%s+%s@%s', $helyi, $this->inbox_token, $domain);
        }

        return sprintf('%s@%s', $this->inbox_token, (string) config('inbox.domain'));
    }

    public function probaidosE(): bool
    {
        return $this->stripe_status !== 'active'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function elofizetettE(): bool
    {
        return in_array($this->stripe_status, ['active', 'trialing'], true);
    }

    public function csomagNeve(): string
    {
        foreach ((array) config('szamlafolyo.plans') as $csomag) {
            if (($csomag['price_id'] ?? null) !== null && $csomag['price_id'] === $this->stripe_price_id) {
                return (string) $csomag['nev'];
            }
        }

        return $this->probaidosE() ? 'Próbaidő' : 'Nincs csomag';
    }

    public function nevRovid(): string
    {
        return Str::limit($this->name, 32);
    }
}
