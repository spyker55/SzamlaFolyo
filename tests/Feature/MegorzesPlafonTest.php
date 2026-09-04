<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\Beallitasok;
use App\Models\Company;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az eredeti bizonylatok megőrzési plafonja.
 *
 * Az űrlap korábban 365 napot engedett. Az eredeti fájl a kiolvasás után már
 * nem kell semmihez — az adat az adatbázisban van, a könyvelő az exportot
 * kapja —, viszont amíg ott van, addig idegen cégek számláit tároljuk egy
 * osztott tárhelyen.
 */
final class MegorzesPlafonTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceg = Company::factory()->create();
        $user = User::factory()->create();
        $this->ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($user);
    }

    public function test_a_plafon_alatti_ertek_mentheto(): void
    {
        Livewire::test(Beallitasok::class)
            ->set('cegNev', 'Teszt Kft.')
            ->set('megorzesiNapok', Company::MEGORZES_MAX_NAP)
            ->call('cegMentes')
            ->assertHasNoErrors('megorzesiNapok');

        $this->assertSame(Company::MEGORZES_MAX_NAP, (int) $this->ceg->fresh()->file_retention_days);
    }

    public function test_a_plafon_folotti_ertek_elutasitva(): void
    {
        Livewire::test(Beallitasok::class)
            ->set('cegNev', 'Teszt Kft.')
            ->set('megorzesiNapok', Company::MEGORZES_MAX_NAP + 1)
            ->call('cegMentes')
            ->assertHasErrors('megorzesiNapok');
    }

    /**
     * A régi, magasabb érték ott maradhatott az adatbázisban. A migráció
     * levágja, de az olvasás oldalán is levágjuk: egy elfelejtett sor nem
     * tarthat fájlokat a plafonon túl.
     */
    public function test_a_tarolt_magasabb_ertek_levagva(): void
    {
        $this->ceg->forceFill(['file_retention_days' => 365])->save();

        $this->assertSame(Company::MEGORZES_MAX_NAP, $this->ceg->fresh()->megorzesiNapok());
    }

    public function test_a_nulla_marad_nulla(): void
    {
        $this->ceg->forceFill(['file_retention_days' => 0])->save();

        $this->assertSame(0, $this->ceg->fresh()->megorzesiNapok());
    }
}
