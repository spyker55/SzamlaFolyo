<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Regisztracio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Amíg az oldal fejlesztés alatt áll, nyilvános regisztráció nincs.
 *
 * A kollégák meghívása ettől független: azt a cég tulajdonosa intézi belépve,
 * a Beállítások képernyőn — az nem nyilvános regisztráció.
 */
final class RegisztracioZarvaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['szamlafolyo.regisztracio_nyitva' => false]);
    }

    public function test_a_zart_regisztracio_helyett_magyarazatot_mutat(): void
    {
        $this->get(route('regisztracio'))
            ->assertOk()
            ->assertSee('A regisztráció még zárva')
            ->assertSee('info@szamlafolyo.hu')
            ->assertDontSee('Fiók létrehozása');
    }

    /**
     * A lényegi állítás. A Livewire-akciók a saját /livewire/update
     * végpontjukon mennek, nem a `regisztracio` útvonalon — egy útvonalra
     * tett őr tehát nem elég: aki a lezárás előtt nyitotta meg az oldalt,
     * vagy maga állítja össze a kérést, megkerülné.
     */
    public function test_a_regisztracios_akciot_akkor_sem_lehet_lefuttatni(): void
    {
        Livewire::test(Regisztracio::class)
            ->set('nev', 'Betolakodó')
            ->set('email', 'uj@example.com')
            ->set('jelszo', 'Nagyon-Hosszu-Jelszo-1')
            ->set('jelszo_megerosites', 'Nagyon-Hosszu-Jelszo-1')
            ->call('regisztracio')
            ->assertForbidden();

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_a_bejelentkezes_nem_kinal_regisztraciot(): void
    {
        $this->get(route('bejelentkezes'))
            ->assertOk()
            ->assertDontSee('Regisztrálok')
            ->assertSee('Új fiókot jelenleg nem lehet nyitni');
    }

    /** A zár egyetlen kapcsolóval oldható, újratelepítés nélkül. */
    public function test_nyitva_allapotban_ujra_lehet_regisztralni(): void
    {
        config(['szamlafolyo.regisztracio_nyitva' => true]);

        $this->get(route('regisztracio'))->assertOk()->assertSee('Fiók létrehozása');

        Livewire::test(Regisztracio::class)
            ->set('nev', 'Új Felhasználó')
            ->set('email', 'uj@example.com')
            ->set('jelszo', 'Nagyon-Hosszu-Jelszo-1')
            ->set('jelszo_megerosites', 'Nagyon-Hosszu-Jelszo-1')
            ->set('feltetelek', true)
            ->call('regisztracio')
            ->assertHasNoErrors();

        $this->assertSame(1, User::query()->count());
    }
}
