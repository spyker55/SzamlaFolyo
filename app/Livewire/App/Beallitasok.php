<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\Szerep;
use App\Livewire\Concerns\Uzenetek;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Kvota;
use App\Services\Billing\StripeSzolgaltatas;
use App\Support\Adoszam;
use App\Support\Berlo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Beallitasok extends Component
{
    use Uzenetek;

    public string $cegNev = '';

    public string $cegAdoszam = '';

    public int $megorzesiNapok = 0;

    public string $ujTagEmail = '';

    public string $ujTagSzerep = 'szerkeszto';

    public function mount(): void
    {
        $ceg = app(Berlo::class)->kotelezo();
        $this->cegNev = (string) $ceg->name;
        $this->cegAdoszam = (string) $ceg->tax_number;
        $this->megorzesiNapok = (int) $ceg->file_retention_days;
    }

    public function cegMentes(): void
    {
        $this->kellSzerep(fn (Szerep $s) => $s->adminisztralhat());

        $adatok = $this->validate([
            'cegNev' => ['required', 'string', 'min:2', 'max:255'],
            'cegAdoszam' => ['nullable', 'string', 'max:30'],
            'megorzesiNapok' => ['required', 'integer', 'min:0', 'max:365'],
        ], attributes: [
            'cegNev' => 'cégnév',
            'cegAdoszam' => 'adószám',
            'megorzesiNapok' => 'megőrzési idő',
        ]);

        if (Adoszam::biztosanRossz($adatok['cegAdoszam'] ?? null)) {
            $this->addError('cegAdoszam', 'Ez az adószám nem lehet helyes — az ellenőrző számjegye nem stimmel.');

            return;
        }

        app(Berlo::class)->kotelezo()->update([
            'name' => $adatok['cegNev'],
            'tax_number' => Adoszam::formaz($adatok['cegAdoszam'] ?? null),
            'file_retention_days' => $adatok['megorzesiNapok'],
        ]);

        $this->uzenet('A cégadatok mentve.');
    }

    /**
     * Tag felvétele. Aki még nem regisztrált, annak létrejön a fiókja egy
     * véletlen jelszóval — a jelszó-emlékeztetővel tud belépni. Így nincs
     * külön meghívó-tábla és lejáró token, amit karban kellene tartani.
     */
    public function tagFelvetel(): void
    {
        $this->kellSzerep(fn (Szerep $s) => $s->adminisztralhat());

        $adatok = $this->validate([
            'ujTagEmail' => ['required', 'email', 'max:255'],
            'ujTagSzerep' => ['required', 'in:tulajdonos,szerkeszto,megtekinto'],
        ], attributes: ['ujTagEmail' => 'e-mail cím']);

        $ceg = app(Berlo::class)->kotelezo();
        $user = User::query()->where('email', $adatok['ujTagEmail'])->first();

        if ($user === null) {
            $user = User::create([
                'name' => Str::before($adatok['ujTagEmail'], '@'),
                'email' => $adatok['ujTagEmail'],
                'password' => Str::random(40),
            ]);
        }

        if ($ceg->users()->where('users.id', $user->id)->exists()) {
            $this->addError('ujTagEmail', 'Ez a felhasználó már tagja a cégnek.');

            return;
        }

        $ceg->users()->attach($user->id, [
            'role' => $adatok['ujTagSzerep'],
            'accepted_at' => now(),
        ]);

        ActivityLog::rogzit('tag.felveve', null, $user->email, ['szerep' => $adatok['ujTagSzerep']]);

        $this->ujTagEmail = '';
        $this->uzenet("{$user->email} hozzáadva. Ha még nem volt fiókja, a bejelentkezésnél az „Elfelejtettem” linkkel tud jelszót beállítani.");
    }

    public function tagEltavolitas(int $userId): void
    {
        $this->kellSzerep(fn (Szerep $s) => $s->adminisztralhat());

        $ceg = app(Berlo::class)->kotelezo();

        if ($userId === auth()->id()) {
            $this->addError('ujTagEmail', 'Magadat nem távolíthatod el.');

            return;
        }

        $user = User::query()->findOrFail($userId);
        $ceg->users()->detach($userId);

        ActivityLog::rogzit('tag.eltavolitva', null, $user->email);
    }

    public function elofizetes(string $csomag): void
    {
        $this->kellSzerep(fn (Szerep $s) => $s->adminisztralhat());

        $priceId = config("szamlafolyo.plans.{$csomag}.price_id");
        $stripe = app(StripeSzolgaltatas::class);

        if (! $stripe->beallitva() || ! is_string($priceId) || $priceId === '') {
            $this->addError('elofizetes', 'A fizetés még nincs beállítva ezen a példányon.');

            return;
        }

        $url = $stripe->checkoutUrl(
            app(Berlo::class)->kotelezo(),
            (string) auth()->user()->email,
            $priceId,
            route('beallitasok').'?fizetes=siker',
            route('beallitasok').'?fizetes=megse',
        );

        $this->redirect($url);
    }

    public function portal(): void
    {
        $this->kellSzerep(fn (Szerep $s) => $s->adminisztralhat());

        $stripe = app(StripeSzolgaltatas::class);

        if (! $stripe->beallitva()) {
            $this->addError('elofizetes', 'A fizetés még nincs beállítva ezen a példányon.');

            return;
        }

        $this->redirect($stripe->portalUrl(
            app(Berlo::class)->kotelezo(),
            (string) auth()->user()->email,
            route('beallitasok'),
        ));
    }

    private function kellSzerep(callable $feltetel): void
    {
        $ceg = app(Berlo::class)->kotelezo();
        $szerep = auth()->user()?->szerepe($ceg);

        abort_unless($szerep !== null && $feltetel($szerep), 403);
    }

    public function render()
    {
        $ceg = app(Berlo::class)->kotelezo();
        $kvota = new Kvota($ceg);

        return view('livewire.app.beallitasok', [
            'ceg' => $ceg,
            'tagok' => $ceg->users()->orderBy('users.id')->get(),
            'szerepek' => Szerep::opciok(),
            'keret' => $kvota->keret(),
            'felhasznalt' => $kvota->felhasznalt(),
            'csomagok' => (array) config('szamlafolyo.plans'),
            'sajatSzerep' => auth()->user()?->szerepe($ceg),
            'tarhelyBajt' => $this->tarhelyFoglalas($ceg),
        ]);
    }

    /** Mennyit foglal a cég a tárhelyből — 1,5 GB-on ez nem elméleti kérdés. */
    private function tarhelyFoglalas(Company $ceg): int
    {
        $osszeg = 0;
        $lemez = Storage::disk('local');

        foreach (["iratok/{$ceg->id}", "exportok/{$ceg->id}"] as $konyvtar) {
            foreach ($lemez->allFiles($konyvtar) as $fajl) {
                $osszeg += $lemez->size($fajl);
            }
        }

        return $osszeg;
    }
}
