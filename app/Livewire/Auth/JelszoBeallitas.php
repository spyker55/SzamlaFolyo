<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as JelszoSzabaly;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class JelszoBeallitas extends Component
{
    public string $token = '';

    #[Url]
    public string $email = '';

    public string $jelszo = '';

    public string $jelszo_megerosites = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function beallit(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'jelszo' => ['required', 'confirmed:jelszo_megerosites', JelszoSzabaly::defaults()],
        ], attributes: ['email' => 'e-mail cím', 'jelszo' => 'jelszó']);

        $allapot = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->jelszo,
                'password_confirmation' => $this->jelszo_megerosites,
                'token' => $this->token,
            ],
            function ($user) {
                $user->forceFill([
                    'password' => $this->jelszo,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($allapot !== Password::PasswordReset) {
            $this->addError('email', __($allapot));

            return;
        }

        session()->flash('siker', 'Az új jelszó beállítva. Most már be tudsz lépni.');
        $this->redirect(route('bejelentkezes', absolute: false), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.jelszo-beallitas');
    }
}
