<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Concerns\Uzenetek;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class ElfelejtettJelszo extends Component
{
    use Uzenetek;

    /**
     * Ugyanarra a címre, ugyanarról a gépről ennyi kérés fér bele negyedóránként.
     * A visszaállító levelet nem a kérő kapja, hanem a cím tulajdonosa — enélkül
     * bárki teleszórhatná valaki más postafiókját a mi nevünkben.
     */
    private const CIMENKENT = 3;

    private const CIMENKENT_MASODPERC = 15 * 60;

    /**
     * Egy gép óránként ennyi **különböző** címmel próbálkozhat. A címenkénti
     * korlát önmagában nem fogná meg azt, aki ezer címre küld egyet-egyet — az
     * pedig a levelezőnk hírnevét viszi, nem a felhasználóét.
     */
    private const GEPENKENT = 10;

    private const GEPENKENT_MASODPERC = 60 * 60;

    public string $email = '';

    public function kuldes(): void
    {
        $this->validate(
            ['email' => ['required', 'email']],
            attributes: ['email' => 'e-mail cím'],
        );

        $this->korlatEllenorzes();

        RateLimiter::hit($this->cimKulcs(), self::CIMENKENT_MASODPERC);
        RateLimiter::hit($this->gepKulcs(), self::GEPENKENT_MASODPERC);

        Password::sendResetLink(['email' => $this->email]);

        // Szándékosan mindig ugyanaz a válasz: különben ez a képernyő
        // megmondaná egy idegennek, hogy melyik e-mail cím van nálunk fiókkal.
        $this->uzenet('Ha ehhez a címhez tartozik fiók, elküldtük rá a jelszóbeállító linket.');
        $this->email = '';
    }

    /**
     * A korlát a kérőről szól, nem a címről: attól, hogy valaki sokat
     * próbálkozott, még nem derül ki, van-e fiók az adott címhez.
     */
    private function korlatEllenorzes(): void
    {
        foreach ([$this->cimKulcs(), $this->gepKulcs()] as $kulcs) {
            $korlat = $kulcs === $this->cimKulcs() ? self::CIMENKENT : self::GEPENKENT;

            if (! RateLimiter::tooManyAttempts($kulcs, $korlat)) {
                continue;
            }

            $perc = (int) ceil(RateLimiter::availableIn($kulcs) / 60);

            throw ValidationException::withMessages([
                'email' => "Túl sok kérés. Próbáld újra {$perc} perc múlva.",
            ]);
        }
    }

    private function cimKulcs(): string
    {
        return 'jelszo-emlekezteto:'.Str::transliterate(Str::lower($this->email)).'|'.request()->ip();
    }

    private function gepKulcs(): string
    {
        return 'jelszo-emlekezteto-gep:'.request()->ip();
    }

    public function render()
    {
        return view('livewire.auth.elfelejtett-jelszo');
    }
}
