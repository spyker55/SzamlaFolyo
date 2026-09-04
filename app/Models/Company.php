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

    /**
     * Meddig őrizhetők az eredeti bizonylatok az export után.
     *
     * A plafon szándékosan alacsony. Az eredeti fájl a kiolvasás után már nem
     * kell semmihez — az adat az adatbázisban van, a könyvelő az exportot
     * kapja —, viszont amíg ott van, addig egy idegen cég számláit tároljuk
     * egy osztott tárhelyen. Ami nincs meg, azt nem is lehet kiszivárogtatni.
     *
     * Ugyanez a plafon vonatkozik a beérkeztető postafiókra is: a levélben
     * ugyanannak a számlának a másolata ül, és hiába töröljük itt a fájlt, ha
     * ott megmarad. Lásd `PostafiokOlvaso::takarit()`.
     */
    public const MEGORZES_MAX_NAP = 7;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'file_retention_days' => 'integer',
            'overage_enabled' => 'boolean',
            'overage_limit_ft' => 'integer',
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

    /**
     * A ténylegesen érvényes megőrzési idő.
     *
     * Nem a tárolt értéket adja vissza, hanem a plafonnal levágottat: a
     * beállítás régebbi, magasabb értékkel is bekerülhetett az adatbázisba,
     * és egy elfelejtett sor nem tarthat fájlokat a plafonon túl.
     */
    public function megorzesiNapok(): int
    {
        return max(0, min((int) $this->file_retention_days, self::MEGORZES_MAX_NAP));
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

    /**
     * A cég csomagja az árazonosító alapján.
     *
     * `null`, ha az előfizetés olyan árra szól, amit a konfiguráció nem ismer.
     * Ez nem elméleti eset: egy Stripe-ban létrehozott, de az `.env`-be be nem
     * írt ár pontosan ide vezet.
     *
     * @return array<string, mixed>|null
     */
    public function csomag(): ?array
    {
        if ($this->stripe_price_id === null) {
            return null;
        }

        foreach ((array) config('szamlafolyo.plans') as $kulcs => $csomag) {
            if (($csomag['price_id'] ?? null) !== null && $csomag['price_id'] === $this->stripe_price_id) {
                return $csomag + ['kulcs' => $kulcs];
            }
        }

        return null;
    }

    public function csomagNeve(): string
    {
        $csomag = $this->csomag();

        if ($csomag !== null) {
            return (string) $csomag['nev'];
        }

        if ($this->elofizetettE()) {
            return 'Ismeretlen csomag';
        }

        return $this->probaidosE() ? 'Próbaidő' : 'Nincs csomag';
    }

    /**
     * Hány felhasználó tartozhat a céghez. Próbaidőben a konfigurációban
     * megadott szám; ismeretlen csomagnál a legkisebb — felfelé sosem
     * tévedünk egy olyan állításban, ami nincs kifizetve.
     */
    public function felhasznaloKeret(): int
    {
        $csomag = $this->csomag();

        if ($csomag !== null) {
            return (int) $csomag['users'];
        }

        return $this->probaidosE()
            ? (int) config('szamlafolyo.trial.users')
            : (int) config('szamlafolyo.plans.kicsi.users');
    }

    /** Engedélyezte-e a tulajdonos a keret fölötti, darabonként számlázott munkát. */
    public function tulhasznalatEngedve(): bool
    {
        return (bool) $this->overage_enabled && $this->csomag() !== null && $this->elofizetettE();
    }

    /**
     * Mennyit költhet a cég egy időszakban a kereten felül, forintban.
     *
     * `null` = nincs plafon. Ezt csak az tudja előállítani, aki a mezőt
     * tudatosan kiürítette: bekapcsoláskor a konfiguráció alapértéke kerül ide.
     */
    public function tulhasznalatPlafon(): ?int
    {
        return $this->overage_limit_ft === null ? null : max(0, (int) $this->overage_limit_ft);
    }

    public function nevRovid(): string
    {
        return Str::limit($this->name, 32);
    }
}
