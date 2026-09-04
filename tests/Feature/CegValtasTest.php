<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\CegLetrehozas;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Billing\Kvota;
use App\Support\CegValasztas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A cégváltó.
 *
 * Két dolgot kell bizonyítania, és a második a fontosabb:
 *
 * 1. Egy több céghez tartozó felhasználó tud váltani, és a választás kitart.
 * 2. A választás **nem** hitelesítés. A munkamenetbe írt azonosítót minden
 *    olvasásnál a tagsághoz mérjük, különben a cégváltó pont azt a falat
 *    bontaná le, amit a `BerloElkulonitesTest` őriz.
 */
final class CegValtasTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Company, 2: Company} */
    private function ketCegesFelhasznalo(): array
    {
        $user = User::factory()->create();

        $a = Company::factory()->create(['name' => 'Alfa Kft.']);
        $b = Company::factory()->create(['name' => 'Béta Bt.']);

        $a->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);
        $b->users()->attach($user->id, ['role' => Szerep::Szerkeszto->value, 'accepted_at' => now()]);

        return [$user, $a, $b];
    }

    // — 1. A váltás működik ——————————————————————————————————

    public function test_alapbol_az_elso_ceg_az_aktiv(): void
    {
        [$user, $a] = $this->ketCegesFelhasznalo();

        $this->actingAs($user)->get(route('beerkezo'))->assertOk()->assertSee($a->name);
    }

    public function test_a_valtas_atallitja_az_aktiv_ceget(): void
    {
        [$user, , $b] = $this->ketCegesFelhasznalo();

        $this->actingAs($user)
            ->post(route('ceg.valtas'), ['ceg' => $b->id])
            ->assertRedirect(route('beerkezo'))
            ->assertSessionHas('siker');

        $this->assertSame($b->id, session(CegValasztas::KULCS));
        $this->actingAs($user)->get(route('beerkezo'))->assertOk()->assertSee($b->name);
    }

    /** A váltás után a lista is a másik cég adatait mutatja, nem csak a fejléc. */
    public function test_a_valtas_utan_a_masik_ceg_iratai_latszanak(): void
    {
        [$user, $a, $b] = $this->ketCegesFelhasznalo();

        Document::factory()->create(['company_id' => $a->id, 'original_filename' => 'alfa-szamla.pdf']);
        Document::factory()->create(['company_id' => $b->id, 'original_filename' => 'beta-szamla.pdf']);

        $this->actingAs($user)->post(route('ceg.valtas'), ['ceg' => $b->id]);

        $this->actingAs($user)->get(route('beerkezo'))
            ->assertSee('beta-szamla.pdf')
            ->assertDontSee('alfa-szamla.pdf');
    }

    public function test_a_fejlec_felsorolja_a_cegeket(): void
    {
        [$user, $a, $b] = $this->ketCegesFelhasznalo();

        $this->actingAs($user)->get(route('beerkezo'))
            ->assertOk()
            ->assertSee($a->name)
            ->assertSee($b->name)
            ->assertSee('Új cég hozzáadása');
    }

    // — 2. A választás nem hitelesítés ————————————————————————

    public function test_idegen_cegre_nem_lehet_valtani(): void
    {
        [$user, $a] = $this->ketCegesFelhasznalo();
        $idegen = Company::factory()->create(['name' => 'Idegen Zrt.']);

        $this->actingAs($user)
            ->post(route('ceg.valtas'), ['ceg' => $idegen->id])
            ->assertForbidden();

        $this->assertNull(session(CegValasztas::KULCS));
        $this->actingAs($user)->get(route('beerkezo'))->assertSee($a->name);
    }

    /**
     * A megkerülés útja nem a végpont, hanem maga a munkamenet: ha valahogy
     * idegen azonosító kerül bele, az sem cégváltás.
     */
    public function test_a_munkamenetbe_irt_idegen_azonosito_nem_valt_ceget(): void
    {
        [$user, $a] = $this->ketCegesFelhasznalo();
        $idegen = Company::factory()->create(['name' => 'Idegen Zrt.']);

        Document::factory()->create(['company_id' => $idegen->id, 'original_filename' => 'idegen.pdf']);

        $this->actingAs($user)
            ->withSession([CegValasztas::KULCS => $idegen->id])
            ->get(route('beerkezo'))
            ->assertOk()
            ->assertSee($a->name)
            ->assertDontSee('idegen.pdf');
    }

    /**
     * Ez a teszt tartja meg az egész felállást. A route model binding a
     * bérlő-middleware **előtt** fut, és a `User::ceg()`-et kérdezi; ha az nem
     * a választott céget adná, a felület a Béta listáját mutatná, a megnyitott
     * bizonylatot viszont az Alfában keresné.
     */
    public function test_a_valtas_utan_a_masik_ceg_irata_nem_nyithato(): void
    {
        [$user, $a, $b] = $this->ketCegesFelhasznalo();

        // Mindkettő **ellenőrzésre vár**: a képernyő más állapotot amúgy is
        // 404-gyel dob, és akkor a teszt nem a bérlőszűrést bizonyítaná.
        $alfaIrat = Document::factory()->ellenorzesreVar()->create(['company_id' => $a->id]);
        $betaIrat = Document::factory()->ellenorzesreVar()->create(['company_id' => $b->id]);

        $this->actingAs($user)->post(route('ceg.valtas'), ['ceg' => $b->id]);

        $this->actingAs($user)->get(route('ellenorzes', $alfaIrat))->assertNotFound();
        $this->actingAs($user)->get(route('ellenorzes', $betaIrat))->assertOk();
    }

    /** Aki kikerül a cégből, annak a választása magától elévül. */
    public function test_az_ervenytelenne_valt_valasztas_visszaesik_az_elsore(): void
    {
        [$user, $a, $b] = $this->ketCegesFelhasznalo();

        $this->actingAs($user)->post(route('ceg.valtas'), ['ceg' => $b->id]);
        $b->users()->detach($user->id);

        $this->actingAs($user)->get(route('beerkezo'))->assertOk()->assertSee($a->name);
        $this->assertNull(session(CegValasztas::KULCS), 'Az elévült választás a munkamenetben maradt.');
    }

    // — 3. Új cég ————————————————————————————————————————————

    public function test_az_uj_ceg_letrehozasa_at_is_valt_ra(): void
    {
        [$user] = $this->ketCegesFelhasznalo();

        $this->actingAs($user);

        Livewire::test(CegLetrehozas::class)
            ->assertSet('vanMarCege', true)
            ->set('nev', 'Gamma Kft.')
            ->call('letrehoz')
            ->assertHasNoErrors();

        $uj = Company::query()->where('name', 'Gamma Kft.')->firstOrFail();

        $this->assertSame($uj->id, session(CegValasztas::KULCS));
        $this->get(route('beerkezo'))->assertOk()->assertSee('Gamma Kft.');
    }

    /**
     * **A próbaidő a felhasználóé, nem a cégé.**
     *
     * A fejlécből bárki nyithat új céget, és minden új cég egyébként saját
     * 14 napos, 50 dokumentumos próbát kapna: aki kéthetente nyit egyet,
     * örökké ingyen dolgozna. Ez a teszt az a fék, ami ezt megfogja.
     */
    public function test_a_masodik_ceg_nem_kap_probaidot(): void
    {
        [$user] = $this->ketCegesFelhasznalo();

        $this->actingAs($user);

        Livewire::test(CegLetrehozas::class)->set('nev', 'Gamma Kft.')->call('letrehoz');

        $uj = Company::query()->where('name', 'Gamma Kft.')->firstOrFail();

        $this->assertNull($uj->trial_ends_at);
        $this->assertFalse($uj->probaidosE());
        $this->assertSame(0, (new Kvota($uj))->keret());
    }

    /** Az első cég viszont igen — a próba nem tűnhet el a szigorítással. */
    public function test_az_elso_ceg_kap_probaidot(): void
    {
        config(['szamlafolyo.trial.days' => 14]);

        $this->actingAs(User::factory()->create());

        Livewire::test(CegLetrehozas::class)->set('nev', 'Elso Kft.')->call('letrehoz');

        $ceg = Company::query()->where('name', 'Elso Kft.')->firstOrFail();

        $this->assertNotNull($ceg->trial_ends_at);
        $this->assertTrue($ceg->probaidosE());
    }

    /** Cég nélkül a képernyő a belépés része marad, nem „új cég". */
    public function test_ceg_nelkul_a_letrehozas_az_elso_ceg(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CegLetrehozas::class)->assertSet('vanMarCege', false);
    }
}
