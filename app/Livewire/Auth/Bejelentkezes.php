<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class Bejelentkezes extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $jelszo = '';

    public bool $emlekezz = false;

    public function bejelentkezes(): void
    {
        $this->validate();
        $this->korlatEllenorzes();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->jelszo], $this->emlekezz)) {
            RateLimiter::hit($this->korlatKulcs());

            throw ValidationException::withMessages([
                'email' => 'Ezzel az e-mail címmel és jelszóval nem tudunk beléptetni.',
            ]);
        }

        RateLimiter::clear($this->korlatKulcs());
        session()->regenerate();

        $this->redirectIntended(route('beerkezo', absolute: false), navigate: true);
    }

    /**
     * Öt hibás próbálkozás után várni kell. A jelszókitalálás olcsó, ha
     * korlátlanul lehet próbálkozni.
     */
    private function korlatEllenorzes(): void
    {
        if (! RateLimiter::tooManyAttempts($this->korlatKulcs(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $mp = RateLimiter::availableIn($this->korlatKulcs());

        throw ValidationException::withMessages([
            'email' => "Túl sok próbálkozás. Próbáld újra {$mp} másodperc múlva.",
        ]);
    }

    private function korlatKulcs(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.bejelentkezes');
    }
}
