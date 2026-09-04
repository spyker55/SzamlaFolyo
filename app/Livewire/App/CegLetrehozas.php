<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\Szerep;
use App\Models\Company;
use App\Support\Adoszam;
use App\Support\CegValasztas;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class CegLetrehozas extends Component
{
    public string $nev = '';

    public string $adoszam = '';

    /**
     * Van-e már cége. Ilyenkor ez a képernyő nem a belépés része, hanem egy
     * **következő** cég nyitása — a szöveg és a visszaút is más.
     */
    public bool $vanMarCege = false;

    public function mount(): void
    {
        $this->vanMarCege = auth()->user()?->ceg() !== null;
    }

    public function letrehoz(): void
    {
        $adatok = $this->validate([
            'nev' => ['required', 'string', 'min:2', 'max:255'],
            'adoszam' => ['nullable', 'string', 'max:30'],
        ], attributes: [
            'nev' => 'cégnév',
            'adoszam' => 'adószám',
        ]);

        if (Adoszam::biztosanRossz($adatok['adoszam'] ?? null)) {
            $this->addError('adoszam', 'Ez az adószám nem lehet helyes — az ellenőrző számjegye nem stimmel.');

            return;
        }

        $user = auth()->user();

        // **A próbaidő a felhasználóé, nem a cégé.** Minden új cég egyébként
        // saját próbát kapna, a fejlécből pedig bárki nyithat újat: aki
        // kéthetente nyit egy céget, örökké ingyen dolgozna. Az első cég kapja
        // a próbát, a többi rögtön csomagot kér.
        $elsoCege = $user->companies()->count() === 0;

        $ceg = DB::transaction(function () use ($adatok, $user, $elsoCege): Company {
            $ceg = new Company([
                'name' => $adatok['nev'],
                'tax_number' => Adoszam::formaz($adatok['adoszam'] ?? null),
            ]);

            if (! $elsoCege) {
                $ceg->trial_ends_at = null;
            }

            $ceg->save();

            $ceg->users()->attach($user->id, [
                'role' => Szerep::Tulajdonos->value,
                'accepted_at' => now(),
            ]);

            return $ceg;
        });

        // Az új cégre át is váltunk. Enélkül a felhasználó a *régi* cég
        // Beérkezőjében kötne ki (a `CegValasztas` az elsőt adja vissza), és
        // azt hinné, nem jött létre semmi.
        CegValasztas::valaszt($user, $ceg->id);

        session()->flash('siker', "A(z) „{$ceg->name}” cég elkészült. A beküldési cím: {$ceg->beerkezteoCim()}");

        $this->redirect(route('beerkezo', absolute: false), navigate: true);
    }

    public function render()
    {
        return view('livewire.app.ceg-letrehozas');
    }
}
