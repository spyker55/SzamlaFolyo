<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\Szerep;
use App\Models\Company;
use App\Support\Adoszam;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class CegLetrehozas extends Component
{
    public string $nev = '';

    public string $adoszam = '';

    public function mount(): void
    {
        // Akinek már van cége, annak itt nincs dolga.
        if (auth()->user()?->ceg() !== null) {
            $this->redirect(route('beerkezo', absolute: false), navigate: true);
        }
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

        $ceg = DB::transaction(function () use ($adatok, $user): Company {
            $ceg = Company::create([
                'name' => $adatok['nev'],
                'tax_number' => Adoszam::formaz($adatok['adoszam'] ?? null),
            ]);

            $ceg->users()->attach($user->id, [
                'role' => Szerep::Tulajdonos->value,
                'accepted_at' => now(),
            ]);

            return $ceg;
        });

        session()->flash('siker', "A(z) „{$ceg->name}” cég elkészült. A beküldési cím: {$ceg->beerkezteoCim()}");

        $this->redirect(route('beerkezo', absolute: false), navigate: true);
    }

    public function render()
    {
        return view('livewire.app.ceg-letrehozas');
    }
}
