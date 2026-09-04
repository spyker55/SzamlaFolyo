<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\Beallitasok;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A kereten felüli feldolgozás felső határa a Beállításokban.
 *
 * A kapcsoló önmagában nyitott végű: bekapcsolva egyetlen elgépelt tömeges
 * feltöltés tetszőleges összegű tételt tud a következő számlára tenni. A
 * plafon az a fék, amitől a kapcsoló vállalható — és épp ezért **nem** elég,
 * hogy a mező létezik: az a fontos, hogy bekapcsoláskor magától bekerüljön egy
 * érték. Aki nem tud a mezőről, azt is védenie kell.
 */
final class TulhasznalatPlafonTest extends TestCase
{
    use RefreshDatabase;

    private function ceg(string $szerep = 'tulajdonos'): Company
    {
        config([
            'szamlafolyo.plans.kicsi.price_id' => 'price_kicsi',
            'szamlafolyo.plans.kicsi.price_id_extra' => 'price_kicsi_extra',
            'szamlafolyo.tulhasznalat.alap_plafon_ft' => 10000,
        ]);

        $ceg = Company::factory()->create([
            'stripe_status' => 'active',
            'stripe_price_id' => 'price_kicsi',
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
        ]);

        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => $szerep, 'accepted_at' => now()]);
        app(Berlo::class)->beallit($ceg);
        $this->actingAs($user);

        return $ceg;
    }

    public function test_a_bekapcsolas_magatol_ad_plafont(): void
    {
        $ceg = $this->ceg();

        $this->assertNull($ceg->overage_limit_ft);

        Livewire::test(Beallitasok::class)->call('tulhasznalatValt');

        $this->assertSame(10000, $ceg->fresh()?->overage_limit_ft);
    }

    /** A már beállított határt a ki-be kapcsolgatás nem írhatja felül. */
    public function test_a_meglevo_plafont_nem_irja_felul(): void
    {
        $ceg = $this->ceg();
        $ceg->update(['overage_limit_ft' => 3000]);

        Livewire::test(Beallitasok::class)->call('tulhasznalatValt');

        $this->assertSame(3000, $ceg->fresh()?->overage_limit_ft);
    }

    public function test_a_plafon_mentheto_es_naplozodik(): void
    {
        $ceg = $this->ceg();
        $ceg->update(['overage_enabled' => true, 'overage_limit_ft' => 10000]);

        Livewire::test(Beallitasok::class)
            ->set('tulhasznalatPlafon', '25000')
            ->call('plafonMentes')
            ->assertHasNoErrors();

        $this->assertSame(25000, $ceg->fresh()?->overage_limit_ft);
        $this->assertTrue(
            ActivityLog::query()->where('action', 'tulhasznalat.plafon')->exists(),
            'A határ emelése nem került a naplóba.',
        );
    }

    /** Üres mező = nincs felső határ. Tudatos döntés, de lehetséges. */
    public function test_az_ures_mezo_torli_a_plafont(): void
    {
        $ceg = $this->ceg();
        $ceg->update(['overage_enabled' => true, 'overage_limit_ft' => 10000]);

        Livewire::test(Beallitasok::class)
            ->set('tulhasznalatPlafon', '')
            ->call('plafonMentes')
            ->assertHasNoErrors();

        $this->assertNull($ceg->fresh()?->overage_limit_ft);
    }

    public function test_a_negativ_ertek_nem_mentheto(): void
    {
        $this->ceg();

        Livewire::test(Beallitasok::class)
            ->set('tulhasznalatPlafon', '-5')
            ->call('plafonMentes')
            ->assertHasErrors('tulhasznalatPlafon');
    }

    /** A korlátot a műveletben kell megfogni, nem az elrejtett űrlapmezővel. */
    public function test_a_szerkeszto_nem_allithat_plafont(): void
    {
        $ceg = $this->ceg(Szerep::Szerkeszto->value);
        $ceg->update(['overage_enabled' => true, 'overage_limit_ft' => 10000]);

        Livewire::test(Beallitasok::class)
            ->set('tulhasznalatPlafon', '999999')
            ->call('plafonMentes');

        $this->assertSame(10000, $ceg->fresh()?->overage_limit_ft);
    }
}
