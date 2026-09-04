<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Services\Billing\Kvota;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Az árazás három szabálya, amelyek mindegyike pénzt mozgat.
 *
 * 1. A keret **kreditben** fogy, nem sorban: egy nyolcvan oldalas köteg nem
 *    kerülhet ugyanannyiba, mint egy egyoldalas nyugta.
 * 2. **Korlátlan csomag nincs.** Korábban véletlenül volt: egy ismeretlen
 *    Stripe-árazonosító `PHP_INT_MAX` keretet kapott, vagyis egy elfelejtett
 *    `.env`-sor csendben korlátlan AI-használatot nyitott.
 * 3. A keret fölött **alapból megállunk.** Váratlan számlát senki ne kapjon
 *    attól, hogy egy hónapban többet dolgozott.
 */
final class ArazasTest extends TestCase
{
    use RefreshDatabase;

    private function kiolvasas(Company $ceg, int $kreditek): void
    {
        $dokumentum = Document::factory()->create(['company_id' => $ceg->id]);

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'prompt_version' => 'teszt',
            'credits' => $kreditek,
        ]);
        $kiolvasas->company_id = $ceg->id;
        $kiolvasas->save();
    }

    private function elofizetett(string $priceId): Company
    {
        $ceg = Company::factory()->create([
            'stripe_status' => 'active',
            'stripe_price_id' => $priceId,
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
        ]);
        app(Berlo::class)->beallit($ceg);

        return $ceg;
    }

    // — 1. Kreditalapú fogyás ————————————————————————————————

    public function test_a_tobboldalas_irat_tobb_kreditet_fogyaszt(): void
    {
        $ceg = Company::factory()->create();
        app(Berlo::class)->beallit($ceg);

        $this->kiolvasas($ceg, 1);
        $this->kiolvasas($ceg, 16);

        $this->assertSame(17, (new Kvota($ceg))->felhasznalt());
    }

    /** A hibába futott kísérlet kreditje sem számít — azért nem fizettünk. */
    public function test_a_hibas_kiserlet_kreditje_sem_fogyaszt(): void
    {
        $ceg = Company::factory()->create();
        app(Berlo::class)->beallit($ceg);

        $dokumentum = Document::factory()->create(['company_id' => $ceg->id]);
        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'prompt_version' => 'teszt',
            'credits' => 12,
            'error' => 'lejárt kulcs',
        ]);
        $kiolvasas->company_id = $ceg->id;
        $kiolvasas->save();

        $this->assertSame(0, (new Kvota($ceg))->felhasznalt());
    }

    // — 2. Korlátlan csomag nincs ————————————————————————————

    public function test_az_ismeretlen_arazonosito_nem_ad_korlatlan_keretet(): void
    {
        $ceg = $this->elofizetett('price_amit_senki_nem_ismer');

        $keret = (new Kvota($ceg))->keret();

        $this->assertSame((int) config('szamlafolyo.plans.kicsi.documents'), $keret);
        $this->assertLessThan(PHP_INT_MAX, $keret);
    }

    public function test_az_eves_ar_ugyanazt_a_csomagot_jelenti(): void
    {
        config(['szamlafolyo.plans.kozepes.price_id_evi' => 'price_kozepes_evi']);

        $ceg = $this->elofizetett('price_kozepes_evi');

        $this->assertSame(200, (new Kvota($ceg))->keret());
        $this->assertStringContainsString('Flow', $ceg->csomagNeve());
        $this->assertStringContainsString('éves', $ceg->csomagNeve());
    }

    // — 3. A keret fölött alapból megállunk ——————————————————

    public function test_alapbol_megall_a_keret_folott(): void
    {
        config(['szamlafolyo.plans.kicsi.price_id' => 'price_kicsi']);
        $ceg = $this->elofizetett('price_kicsi');

        $this->kiolvasas($ceg, 50);

        $kvota = new Kvota($ceg);

        $this->assertSame(0, $kvota->maradek());
        $this->assertFalse($kvota->vanMegKeret());
        $this->assertStringContainsString('Elfogyott a havi kereted', (string) $kvota->akadaly());
    }

    public function test_bekapcsolt_tulhasznalattal_tovabb_mehet(): void
    {
        config([
            'szamlafolyo.plans.kicsi.price_id' => 'price_kicsi',
            'szamlafolyo.plans.kicsi.price_id_extra' => 'price_kicsi_extra',
        ]);
        $ceg = $this->elofizetett('price_kicsi');
        $ceg->update(['overage_enabled' => true]);

        $this->kiolvasas($ceg, 55);

        $kvota = new Kvota($ceg->fresh());

        $this->assertTrue($kvota->vanMegKeret());
        $this->assertNull($kvota->akadaly());
        $this->assertSame(5, $kvota->tullepes());
    }

    /**
     * A kapcsoló önmagában nem elég: előfizetés nélkül (próbaidőben) nincs mit
     * megterhelni, tehát nem is dolgozunk tovább ingyen.
     */
    public function test_probaidoben_a_kapcsolo_nem_nyit_keretet(): void
    {
        $ceg = Company::factory()->create(['overage_enabled' => true]);
        app(Berlo::class)->beallit($ceg);

        $this->kiolvasas($ceg, (int) config('szamlafolyo.trial.documents'));

        $kvota = new Kvota($ceg);

        $this->assertFalse($ceg->tulhasznalatEngedve());
        $this->assertFalse($kvota->vanMegKeret());
    }
}
