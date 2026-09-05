<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Szerep;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps();
    }

    /**
     * A felhasználó cége.
     *
     * A séma (`company_user`) több céget is elbírna, a termék viszont
     * szándékosan egyet mutat: egy könyvelőiroda az ügyfeleit egy fiókban
     * kezeli, és az ügyfelenkénti szétválasztást az export vevő-adószám
     * szűrője adja — nem cégek adminisztrálása.
     */
    public function ceg(): ?Company
    {
        // A **legkorábbi tagság**, nem a legkisebb cégazonosító. A különbség
        // biztonsági: azonosító szerint egy később felvett, de kisebb sorszámú
        // cég maga alá húzta volna azt, ahol a felhasználó addig dolgozott —
        // vagyis egy tagfelvétel elvehette volna valaki más fiókját. A belépés
        // sorrendjén viszont senki nem tud utólag változtatni.
        return $this->companies()
            ->orderBy('company_user.created_at')
            ->orderBy('companies.id')
            ->first();
    }

    public function szerepe(Company $ceg): ?Szerep
    {
        $pivot = $this->companies()->where('companies.id', $ceg->id)->first()?->pivot;

        return $pivot ? Szerep::tryFrom((string) $pivot->role) : null;
    }

    public function monogram(): string
    {
        $reszek = preg_split('/\s+/', trim($this->name)) ?: [];
        $betuk = array_map(static fn (string $r): string => Str::upper(Str::substr($r, 0, 1)), array_slice($reszek, 0, 2));

        return implode('', $betuk) ?: Str::upper(Str::substr($this->email, 0, 1));
    }
}
