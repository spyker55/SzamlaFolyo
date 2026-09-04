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

    /**
     * **Éves ár nem létezhet, amíg a keret a számlázási ciklusra szól.**
     *
     * Ez a teszt nem működést véd, hanem egy döntést: a `Kvota::idoszak()` a
     * Stripe-ciklust adja vissza, egy éves előfizetésnél tehát évi ötven
     * dokumentum járna a havi ötven helyett — tizenketted termék, tizenhat
     * százalék kedvezményért. Ha valaki felvesz egy éves árat anélkül, hogy az
     * ablakot külön forgó hónapra váltaná, itt fog megbukni, és itt olvassa el,
     * miért.
     */
    public function test_nincs_eves_arazonosito_a_csomagokban(): void
    {
        foreach ((array) config('szamlafolyo.plans') as $kulcs => $csomag) {
            $this->assertArrayNotHasKey('price_id_evi', $csomag, "A(z) {$kulcs} csomagban éves árazonosító van.");
            $this->assertArrayNotHasKey('ar_evi', $csomag, "A(z) {$kulcs} csomagban éves ár van.");
        }
    }

    /** A keret az előfizetési ciklusra szól: az azelőtti fogyás nem terheli. */
    public function test_a_keret_a_szamlazasi_ciklusra_szol(): void
    {
        config(['szamlafolyo.plans.kicsi.price_id' => 'price_kicsi']);
        $ceg = $this->elofizetett('price_kicsi');

        $this->kiolvasas($ceg, 10);
        DocumentExtraction::query()->withoutGlobalScopes()
            ->update(['created_at' => now()->subDays(10)]);

        $this->kiolvasas($ceg, 4);

        $kvota = new Kvota($ceg);

        $this->assertSame(4, $kvota->felhasznalt(), 'A ciklus előtti kiolvasás is beleszámított.');
        $this->assertSame(
            $ceg->current_period_start->timestamp,
            $kvota->idoszak()[0]->timestamp,
        );
    }

    // — 4. A túlhasználatnak felső határa van ————————————————

    /**
     * A kapcsoló nem nyitott végű. A plafon nélkül egyetlen elgépelt tömeges
     * feltöltés tetszőleges összeget tudna a következő számlára tenni, és a
     * felhasználó erről a számlán értesülne először.
     */
    public function test_a_plafon_megallitja_a_tulhasznalatot(): void
    {
        config([
            'szamlafolyo.plans.kicsi.price_id' => 'price_kicsi',
            'szamlafolyo.plans.kicsi.documents' => 50,
            'szamlafolyo.plans.kicsi.extra_ft' => 49,
        ]);

        $ceg = $this->elofizetett('price_kicsi');
        $ceg->update(['overage_enabled' => true, 'overage_limit_ft' => 1000]);

        // 50 a kereten belül, 20 fölötte: 20 × 49 = 980 Ft, még a plafon alatt.
        $this->kiolvasas($ceg, 70);
        $kvota = new Kvota($ceg);

        $this->assertSame(20, $kvota->tullepes());
        $this->assertSame(980, $kvota->tullepesFt());
        $this->assertTrue($kvota->vanMegKeret(), 'A plafon alatt még mehetne tovább.');

        // Még egy kredit: 21 × 49 = 1 029 Ft, ez már fölötte van.
        $this->kiolvasas($ceg, 1);
        $kvota = new Kvota($ceg->fresh());

        $this->assertSame(1029, $kvota->tullepesFt());
        $this->assertFalse($kvota->vanMegKeret(), 'A plafon fölött is tovább engedte.');
        $this->assertStringContainsString('1 000 Ft', (string) $kvota->akadaly());
    }

    /** Üres plafon = nincs fék. Ez csak tudatos döntéssel állítható elő. */
    public function test_plafon_nelkul_nincs_felso_hatar(): void
    {
        config(['szamlafolyo.plans.kicsi.price_id' => 'price_kicsi']);

        $ceg = $this->elofizetett('price_kicsi');
        $ceg->update(['overage_enabled' => true, 'overage_limit_ft' => null]);

        $this->kiolvasas($ceg, 5000);

        $this->assertTrue((new Kvota($ceg))->vanMegKeret());
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
