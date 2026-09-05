<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class Regisztracio extends Component
{
    public string $nev = '';

    public string $email = '';

    public string $jelszo = '';

    public string $jelszo_megerosites = '';

    /**
     * Az ÁSZF elfogadása. Nem az űrlap dísze: enélkül nincs mire hivatkozni,
     * ha később vita lesz arról, mit vállalt a felhasználó — ezért a jelölés a
     * **műveletben** kötelező, nem a gomb letiltásával.
     */
    public bool $aszf = false;

    /** Nyitva van-e a nyilvános regisztráció. A nézet ebből dönt. */
    public function nyitva(): bool
    {
        return (bool) config('szamlafolyo.regisztracio_nyitva');
    }

    public function regisztracio(): void
    {
        // A zárat itt kell tartani, nem (csak) az útvonalon: a Livewire-akciók
        // a saját /livewire/update végpontjukon mennek, nem a `regisztracio`
        // útvonalon. Aki a lezárás előtt nyitotta meg az oldalt — vagy maga
        // állítja össze a kérést —, az útvonal-őrt megkerülné.
        abort_unless($this->nyitva(), 403, 'A regisztráció jelenleg zárva van.');

        $adatok = $this->validate([
            'nev' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'jelszo' => ['required', 'string', 'confirmed:jelszo_megerosites', Password::defaults()],
            'aszf' => ['accepted'],
        ], messages: [
            // Saját mondat, nem a sablon: a magyar ragozás egy `:attribute`
            // helyettesítéssel itt nem jönne ki helyesen.
            'aszf.accepted' => 'A regisztrációhoz el kell fogadnod az Általános Szerződési Feltételeket.',
        ], attributes: [
            'nev' => 'név',
            'email' => 'e-mail cím',
            'jelszo' => 'jelszó',
        ]);

        $user = User::create([
            'name' => $adatok['nev'],
            'email' => $adatok['email'],
            'password' => $adatok['jelszo'],
        ]);

        event(new Registered($user));
        Auth::login($user);
        session()->regenerate();

        // Cég nélkül nincs mit csinálni a rendszerben — egyenesen a
        // cégnyitóra megyünk, nem egy üres kezdőlapra.
        $this->redirect(route('ceg.letrehozas', absolute: false), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.regisztracio');
    }
}
