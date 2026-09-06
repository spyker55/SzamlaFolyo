<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Services\Account\FiokTorlo;
use App\Services\Account\TorlesAkadaly;
use App\Support\Berlo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A saját fiók törlése.
 *
 * Külön képernyő, nem egy doboz a Beállítások alján. Ez az egyetlen művelet a
 * rendszerben, amit nem lehet visszavonni és nincs mögötte mentés — itt ne
 * versenyezzen a figyelemért egy adószám-mezővel. Amit a felhasználó itt lát,
 * az pontosan az, ami történni fog: a szöveget ugyanaz a `TorlesTerv` adja,
 * amit a gomb megnyomása után a szolgáltatás végrehajt.
 */
#[Layout('components.layouts.app')]
class FiokTorles extends Component
{
    /** Percenként ennyi jelszópróbálkozás fér bele. */
    private const PROBALKOZAS = 5;

    public string $jelszo = '';

    /**
     * A bérlőt itt magunknak kell beállítanunk.
     *
     * Ez a képernyő kimarad a `ceg` middleware-ből — anélkül a céget sosem
     * alapított felhasználó örökre a cégalapításra volna irányítva —, így a
     * `Berlo` üresen érkezne, és az elrendezés fejlécében üres cégnév állna.
     * Aki nem tartozik céghez, annál marad a `null`, amit az elrendezés kezel.
     */
    public function mount(): void
    {
        app(Berlo::class)->beallit(auth()->user()?->ceg());
    }

    /**
     * A jelszó azért kell, és nem egy „írd be, hogy TÖRLÉS" mező.
     *
     * A begépelt varázsszó egy nyitva hagyott laptop mellől is beírható, és
     * pont az ellen nem véd, ami ellen kellene: az átvett munkamenet ellen.
     * A jelszó viszont olyasmi, amit a támadó nem lát a képernyőn. Aki a
     * saját jelszavát nem tudja — mert meghívóval került be —, az az
     * „Elfelejtettem" linkkel tud beállítani egyet; fiókot megsemmisíteni
     * csak az tudjon, aki bizonyítani tudja, hogy az övé.
     */
    public function torles(): void
    {
        $user = auth()->user();

        $this->validate(
            ['jelszo' => ['required', 'string']],
            attributes: ['jelszo' => 'jelszó'],
        );

        $kulcs = 'fiok-torles:'.$user->id;

        if (RateLimiter::tooManyAttempts($kulcs, self::PROBALKOZAS)) {
            $this->addError('jelszo', sprintf(
                'Túl sok próbálkozás. Várj %d másodpercet.',
                RateLimiter::availableIn($kulcs),
            ));

            return;
        }

        if (! Hash::check($this->jelszo, (string) $user->password)) {
            RateLimiter::hit($kulcs);
            $this->addError('jelszo', 'A jelszó nem stimmel.');

            return;
        }

        RateLimiter::clear($kulcs);

        // Az akadályt a kijelentkezés **előtt** kell megnéznünk: aki nem
        // törölhet, az maradjon bent, és lássa a képernyőn, mit tegyen.
        $terv = app(FiokTorlo::class)->terv($user);

        if (! $terv->mehet()) {
            $this->addError('jelszo', $terv->akadaly->getMessage());

            return;
        }

        /*
         * Kijelentkezés a törlés ELŐTT — a sorrend nem ízlés kérdése.
         *
         * A `logout()` friss „emlékezz rám" tokent ír a felhasználó sorába.
         * Egy már törölt modellen viszont az Eloquent `exists` jelzője
         * hamis, ezért ugyanez a mentés **INSERT**-té válik: a fiók a saját
         * azonosítójával visszakerül az adatbázisba. A cég ilyenkor törölve
         * marad, a felhasználó viszont feltámad — vagyis a „töröltem a
         * fiókom" után ott áll a fiók. Ez a hiba némán megy át minden
         * felületi próbán; a `test_a_torolt_fiok_nem_tamad_fel` a zár.
         */
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        try {
            $terv = app(FiokTorlo::class)->torol($user);
        } catch (TorlesAkadaly $hiba) {
            // Idáig csak a számlázó hibája juthat el. A munkamenet már nincs
            // meg, ezért az üzenet a bejelentkező képernyőre megy: a
            // felhasználó belép, és újrapróbálhatja.
            session()->flash('hiba', $hiba->getMessage());
            $this->redirect(route('bejelentkezes'));

            return;
        }

        // A `siker` kulcsot az auth-elrendezés már kiírja, és a
        // kijelentkezés is oda tér vissza — a törlés ne találjon ki magának
        // egy másik utat ugyanarra.
        // Az előfizetést csak akkor említjük, ha volt: a próbaidős fióknak
        // nincs mit lemondani, és egy meg nem történt lépésről beszámolni
        // ugyanolyan hiba, mint elhallgatni egy megtörténtet.
        session()->flash('siker', match (true) {
            $terv->cegIsTorlodik && $terv->vanElofizetes => 'A fiókod és a cég minden adata törölve, az előfizetés lemondva.',
            $terv->cegIsTorlodik => 'A fiókod és a cég minden adata törölve.',
            default => 'A fiókod törölve. A cég adatai a többi felhasználónál megmaradtak.',
        });

        $this->redirect(route('bejelentkezes'));
    }

    public function render()
    {
        return view('livewire.app.fiok-torles', [
            'terv' => app(FiokTorlo::class)->terv(auth()->user()),
        ]);
    }
}
