<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Services\Billing\Kvota;
use App\Services\Extraction\Sorkezelo;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KvotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_probaido_alatt_a_probaidos_keret_szamit(): void
    {
        $ceg = Company::factory()->create();
        app(Berlo::class)->beallit($ceg);

        $kvota = new Kvota($ceg);

        $this->assertSame((int) config('szamlafolyo.trial.documents'), $kvota->keret());
        $this->assertSame(0, $kvota->felhasznalt());
        $this->assertTrue($kvota->vanMegKeret());
        $this->assertNull($kvota->akadaly());
    }

    public function test_lejart_probaido_utan_nincs_keret(): void
    {
        $ceg = Company::factory()->lejartProbaido()->create();
        $kvota = new Kvota($ceg);

        $this->assertSame(0, $kvota->keret());
        $this->assertFalse($kvota->vanMegKeret());
        $this->assertStringContainsString('próbaidő lejárt', (string) $kvota->akadaly());
    }

    /**
     * Csak a ténylegesen kiolvasott dokumentum fogyaszt a keretből: a
     * duplikátumért és a feltöltéskor elakadt fájlért nem fizettünk a
     * modellnek, tehát a felhasználó se fizessen érte.
     */
    public function test_csak_a_kiolvasott_dokumentum_fogyaszt(): void
    {
        $ceg = Company::factory()->create();
        app(Berlo::class)->beallit($ceg);

        // Kiolvasás nélküli sorok
        Document::factory()->count(3)->create(['company_id' => $ceg->id]);

        $this->assertSame(0, (new Kvota($ceg))->felhasznalt());

        // Egy sor, amihez tartozik kiolvasás
        $dokumentum = Document::factory()->create(['company_id' => $ceg->id]);
        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'prompt_version' => 'teszt',
        ]);
        $kiolvasas->company_id = $ceg->id;
        $kiolvasas->save();

        $this->assertSame(1, (new Kvota($ceg))->felhasznalt());
    }

    public function test_keret_felett_nem_indul_uj_kiolvasas(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $ceg = Company::factory()->lejartProbaido()->create();
        app(Berlo::class)->beallit($ceg);

        Document::factory()->create(['company_id' => $ceg->id]);

        $volt = app(Sorkezelo::class)->egyet($ceg);

        $this->assertFalse($volt, 'Keret nélkül nem indulhat kiolvasás.');
        Http::assertNothingSent();
    }

    public function test_elofizetesnel_a_csomag_kerete_szamit(): void
    {
        config()->set('szamlafolyo.plans.kozepes.price_id', 'price_teszt');

        $ceg = Company::factory()->elofizetett('price_teszt')->create();

        $this->assertSame(200, (new Kvota($ceg))->keret());
        $this->assertTrue($ceg->elofizetettE());
    }
}
