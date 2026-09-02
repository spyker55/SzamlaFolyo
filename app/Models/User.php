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
     * A felhasználó cége. A séma több céget is elbír, a felület egyet mutat —
     * ha egyszer kell cégváltó, a kapcsolat már itt van.
     */
    public function ceg(): ?Company
    {
        return $this->companies()->orderBy('companies.id')->first();
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
