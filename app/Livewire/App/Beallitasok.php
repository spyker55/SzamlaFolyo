<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\Szerep;
use App\Livewire\Concerns\Jogosultsag;
use App\Livewire\Concerns\Uzenetek;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Kvota;
use App\Services\Billing\StripeSzolgaltatas;
use App\Services\Ingest\PostafiokOlvaso;
use App\Support\Adoszam;
use App\Support\Berlo;
use App\Support\Osszeg;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Beallitasok extends Component
{
    use Jogosultsag, Uzenetek;

    public string $cegNev = '';

    public string $cegAdoszam = '';

    public int $megorzesiNapok = 0;

    /**
     * A kereten felüli költés felső határa forintban, üresen hagyva nincs.
     *
     * Sztring, mert a beviteli mező üresen is maradhat, és az üres mező nem
     * nulla: a nulla azonnali megállást jelentene, az üres azt, hogy nincs fék.
     */
    public string $tulhasznalatPlafon = '';

    public string $ujTagEmail = '';

    public string $ujTagSzerep = 'szerkeszto';

    public function mount(): void
    {
        $ceg = app(Berlo::class)->kotelezo();
        $this->cegNev = (string) $ceg->name;
        $this->cegAdoszam = (string) $ceg->tax_number;
        $this->megorzesiNapok = $ceg->megorzesiNapok();
        $this->tulhasznalatPlafon = (string) ($ceg->tulhasznalatPlafon() ?? '');
    }

    public function cegMentes(): void
    {
        $this->kellTulajdonos();

        $adatok = $this->validate([
            'cegNev' => ['required', 'string', 'min:2', 'max:255'],
            'cegAdoszam' => ['nullable', 'string', 'max:30'],
            'megorzesiNapok' => ['required', 'integer', 'min:0', 'max:'.Company::MEGORZES_MAX_NAP],
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
        $this->kellTulajdonos();

        $adatok = $this->validate([
            'ujTagEmail' => ['required', 'email', 'max:255'],
            'ujTagSzerep' => ['required', 'in:tulajdonos,szerkeszto,megtekinto'],
        ], attributes: ['ujTagEmail' => 'e-mail cím']);

        $ceg = app(Berlo::class)->kotelezo();
        $user = User::query()->where('email', $adatok['ujTagEmail'])->first();

        if ($user !== null && $ceg->users()->where('users.id', $user->id)->exists()) {
            $this->addError('ujTagEmail', 'Ez a felhasználó már tagja a cégnek.');

            return;
        }

        /*
         * Máshol dolgozó fiókot nem veszünk át.
         *
         * A termék egy felhasználónak egy céget mutat. Amíg ezt nem néztük meg,
         * a felvétel **elvehetett** valakit: a `User::ceg()` a legkorábbi
         * tagságot adja vissza, de a régi, azonosító szerinti sorrendben egy
         * kisebb sorszámú cég egyszerűen maga alá húzta a másikét — a felvett
         * ember a következő belépéskor idegen cég Beérkezőjét látta a sajátja
         * helyett, és ide töltötte volna fel a saját számláit.
         *
         * A hívatlan felvételnek amúgy sincs értelme: a másik cégéhez tartozó
         * fiók itt úgyis egy néma sor maradna a taglistán. Inkább mondjuk meg.
         */
        if ($user !== null && $user->companies()->exists()) {
            $this->addError('ujTagEmail', 'Ez a felhasználó már egy másik céghez tartozik. Előbb ott kell kilépnie.');

            return;
        }

        // A csomag felhasználószáma. Két dolog múlik a sorrenden. Egy: a
        // korlátot a **műveletben** kell megfogni, nem a képernyőn elrejtett
        // gombbal — egy Livewire-akció közvetlenül is meghívható. Kettő: az
        // ellenőrzés a fiók létrehozása **előtt** áll, különben egy elutasított
        // meghívás is hagyna maga után egy árva, véletlen jelszavú fiókot.
        // A `null` korlátlant jelent — ott nincs mit ellenőrizni.
        $keret = $ceg->felhasznaloKeret();

        if ($keret !== null && $ceg->users()->count() >= $keret) {
            $this->addError('ujTagEmail', sprintf(
                'A csomagodban %d felhasználó szerepelhet. Nagyobb csomaggal többen is dolgozhattok.',
                $keret,
            ));

            return;
        }

        if ($user === null) {
            $user = User::create([
                'name' => Str::before($adatok['ujTagEmail'], '@'),
                'email' => $adatok['ujTagEmail'],
                'password' => Str::random(40),
            ]);
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
        $this->kellTulajdonos();

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
        $this->kellTulajdonos();

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
        $this->kellTulajdonos();

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

    /**
     * A keret fölötti, darabonként számlázott feldolgozás ki/be kapcsolása.
     *
     * Külön művelet, külön naplóbejegyzéssel: ez az a kapcsoló, amitől a
     * felhasználónak pénzébe kerülhet egy forgalmas hónap. Ha később vitatja,
     * a naplóból derül ki, ki és mikor kapcsolta be.
     */
    public function tulhasznalatValt(): void
    {
        $this->kellTulajdonos();

        $ceg = app(Berlo::class)->kotelezo();
        $uj = ! $ceg->overage_enabled;

        $valtozas = ['overage_enabled' => $uj];

        // Bekapcsoláskor a cég kap egy alapértelmezett plafont, ha még nincs.
        // A kapcsoló enélkül nyitott végű volna, és a felhasználó a felső
        // határról a számlán értesülne először.
        if ($uj && $ceg->overage_limit_ft === null) {
            $valtozas['overage_limit_ft'] = (int) config('szamlafolyo.tulhasznalat.alap_plafon_ft');
        }

        $ceg->update($valtozas);
        $this->tulhasznalatPlafon = (string) ($ceg->fresh()?->tulhasznalatPlafon() ?? '');

        ActivityLog::rogzit('tulhasznalat.'.($uj ? 'engedve' : 'tiltva'));

        $this->uzenet($uj
            ? 'A keret fölötti dokumentumokat mostantól feldolgozzuk, és darabonként számlázzuk — '
                .'legfeljebb a beállított határig.'
            : 'A keret fölötti feldolgozás kikapcsolva — a keret elfogyásakor a feldolgozás megáll.');
    }

    /**
     * A plafon mentése.
     *
     * Külön művelet, mert pénzügyi következménye van, és külön naplóbejegyzést
     * érdemel: ha valaki később vitatja a számlát, ebből derül ki, ki emelte
     * meg a határt és mikor.
     */
    public function plafonMentes(): void
    {
        $this->kellTulajdonos();

        $this->validate([
            'tulhasznalatPlafon' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ], attributes: ['tulhasznalatPlafon' => 'határ']);

        $ertek = trim($this->tulhasznalatPlafon) === '' ? null : (int) $this->tulhasznalatPlafon;

        app(Berlo::class)->kotelezo()->update(['overage_limit_ft' => $ertek]);

        ActivityLog::rogzit('tulhasznalat.plafon', null, $ertek === null ? 'nincs' : $ertek.' Ft');

        $this->uzenet($ertek === null
            ? 'A kereten felüli feldolgozásnak mostantól nincs felső határa.'
            : 'A kereten felüli feldolgozás felső határa '.Osszeg::formaz($ertek).' Ft.');
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
            'tullepes' => $kvota->tullepes(),
            'tullepesFt' => $kvota->tullepesFt(),
            'csomagok' => (array) config('szamlafolyo.plans'),
            'felhasznaloKeret' => $ceg->felhasznaloKeret(),
            // Túlhasználatot csak akkor kínálunk, ha van mögötte darabár —
            // egy kapcsoló, ami után nem történik számlázás, hazugság.
            'vanExtraAr' => is_string($ceg->csomag()['price_id_extra'] ?? null)
                && ($ceg->csomag()['price_id_extra'] ?? '') !== '',
            'extraFt' => $ceg->csomag()['extra_ft'] ?? null,
            'sajatSzerep' => auth()->user()?->szerepe($ceg),
            'tarhelyBajt' => $this->tarhelyFoglalas($ceg),
            'bekuldesiCim' => $this->bekuldesiCim($ceg),
            // A cím önmagában félrevezető: ha a postafiók nincs beállítva a
            // kiszolgálón, az arra küldött levél sehova nem érkezik meg, és
            // ezt semmi nem mondja meg — se a feladónak, se a felhasználónak.
            'bekuldesAktiv' => PostafiokOlvaso::beallitva(),
        ]);
    }

    /**
     * A cég beküldési e-mail címe.
     *
     * A tokent a `Company` hozza létre, és eddig **sehol nem jelent meg a
     * felületen** — vagyis a beérkeztetés a háttérben kész volt, de nem lehetett
     * megtudni, hova kell küldeni a számlát.
     */
    private function bekuldesiCim(Company $ceg): string
    {
        if ((string) config('inbox.mode') === 'plus') {
            $plusz = (string) config('inbox.plus_address');

            return $plusz === ''
                ? ''
                : (string) str_replace('@', '+'.$ceg->inbox_token.'@', $plusz);
        }

        return $ceg->inbox_token.'@'.config('inbox.domain');
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
