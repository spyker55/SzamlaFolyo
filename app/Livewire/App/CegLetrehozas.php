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

    /**
     * Az adószám **kötelező**, és ez nem adatgyűjtés, hanem kapu.
     *
     * A SzámlaFolyót kizárólag vállalkozások vehetik igénybe (lásd az ÁSZF-et),
     * a fogyasztóvédelmi jog viszont kógens: hiába mondja ezt ki a szerződés,
     * ha a rendszer beenged egy magánszemélyt, rá attól még a fogyasztói
     * szabályok érvényesek. A gyakorlati szűrő az adószám — fogyasztónak nincs.
     *
     * Ezért itt szigorúbban nézzük, mint a bizonylatokon: érvényes **magyar**
     * adószám kell. Az `Adoszam` osztály alapból megengedő (egy külföldi
     * szállító adószáma nem magyar alakú, és attól még helyes), de az a
     * szabály a *partner* adószámára szól. Ez a saját cégé, egy magyar
     * számlafeldolgozó szolgáltatásban. Ha egyszer külföldre is adnánk el,
     * ez az egy feltétel lazul.
     */
    public function letrehoz(): void
    {
        $adatok = $this->validate([
            'nev' => ['required', 'string', 'min:2', 'max:255'],
            'adoszam' => ['required', 'string', 'max:30'],
        ], attributes: [
            'nev' => 'cégnév',
            'adoszam' => 'adószám',
        ]);

        if (! Adoszam::ervenyes($adatok['adoszam'])) {
            // Két külön baj, két külön mondat: aki elgépelt egy számjegyet,
            // annak mást kell mondani, mint aki nem is adószámot írt be.
            $this->addError('adoszam', Adoszam::biztosanRossz($adatok['adoszam'])
                ? 'Ez az adószám nem lehet helyes — az ellenőrző számjegye nem stimmel.'
                : 'Magyar adószámot kérünk, 8 vagy 11 számjeggyel (például 12345678-2-42).');

            return;
        }

        $user = auth()->user();

        $ceg = DB::transaction(function () use ($adatok, $user): Company {
            $ceg = Company::create([
                'name' => $adatok['nev'],
                'tax_number' => Adoszam::formaz($adatok['adoszam']),
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
