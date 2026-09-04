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
 * A csomaghoz tartozó felhasználószám.
 *
 * A korlátot a *műveletben* kell megfogni, nem a képernyőn elrejtett gombbal:
 * egy Livewire-akció közvetlenül is meghívható, tehát a letiltott űrlap nem
 * korlát, csak udvariasság.
 */
final class FelhasznaloKeretTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    private function belep(Company $ceg): User
    {
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);
        app(Berlo::class)->beallit($ceg);
        $this->actingAs($user);

        return $user;
    }

    public function test_probaidoben_a_konfiguralt_keret_szamit(): void
    {
        config(['szamlafolyo.trial.users' => 3]);

        $ceg = Company::factory()->create();

        $this->assertSame(3, $ceg->felhasznaloKeret());
    }

    public function test_a_csomag_keretet_orokli(): void
    {
        config(['szamlafolyo.plans.nagy.price_id' => 'price_nagy']);

        $ceg = Company::factory()->create(['stripe_status' => 'active', 'stripe_price_id' => 'price_nagy']);

        $this->assertSame(10, $ceg->felhasznaloKeret());
    }

    /** Ismeretlen árnál lefelé tévedünk: nem adunk olyat, ami nincs kifizetve. */
    public function test_ismeretlen_arnal_a_legkisebb_keret(): void
    {
        $ceg = Company::factory()->create(['stripe_status' => 'active', 'stripe_price_id' => 'price_ismeretlen']);

        $this->assertSame((int) config('szamlafolyo.plans.kicsi.users'), $ceg->felhasznaloKeret());
    }

    public function test_a_keret_folott_nem_vesz_fel_uj_tagot(): void
    {
        config(['szamlafolyo.trial.users' => 1]);

        $ceg = Company::factory()->create();
        $this->belep($ceg);   // ezzel már 1 tag van

        Livewire::test(Beallitasok::class)
            ->set('ujTagEmail', 'kollega@pelda.hu')
            ->call('tagFelvetel')
            ->assertHasErrors('ujTagEmail');

        $this->assertSame(1, $ceg->fresh()->users()->count());
        $this->assertDatabaseMissing('users', ['email' => 'kollega@pelda.hu']);
    }

    public function test_a_kereten_belul_felvesz(): void
    {
        config(['szamlafolyo.trial.users' => 3]);

        $ceg = Company::factory()->create();
        $this->belep($ceg);

        Livewire::test(Beallitasok::class)
            ->set('ujTagEmail', 'kollega@pelda.hu')
            ->call('tagFelvetel')
            ->assertHasNoErrors('ujTagEmail');

        $this->assertSame(2, $ceg->fresh()->users()->count());
    }
}
