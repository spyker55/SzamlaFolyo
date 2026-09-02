<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Concerns\Uzenetek;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class ElfelejtettJelszo extends Component
{
    use Uzenetek;

    public string $email = '';

    public function kuldes(): void
    {
        $this->validate(
            ['email' => ['required', 'email']],
            attributes: ['email' => 'e-mail cím'],
        );

        Password::sendResetLink(['email' => $this->email]);

        // Szándékosan mindig ugyanaz a válasz: különben ez a képernyő
        // megmondaná egy idegennek, hogy melyik e-mail cím van nálunk fiókkal.
        $this->uzenet('Ha ehhez a címhez tartozik fiók, elküldtük rá a jelszóbeállító linket.');
        $this->email = '';
    }

    public function render()
    {
        return view('livewire.auth.elfelejtett-jelszo');
    }
}
