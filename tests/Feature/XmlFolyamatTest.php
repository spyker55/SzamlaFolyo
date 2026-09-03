<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumAllapot;
use App\Enums\Szerep;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Extraction\Sorkezelo;
use App\Services\Files\FajlTarolo;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\XmlFixtura;
use Tests\TestCase;

/**
 * A feldolgozási lánc legolcsóbb foka végponttól végpontig.
 *
 * A lényegi állítás nem az, hogy jó adat jön ki — az a XmlKiolvasoTest dolga —,
 * hanem hogy **modellhívás nélkül** jön ki.
 */
final class XmlFolyamatTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ceg = Company::factory()->create(['tax_number' => '10773381-2-44']);
        $this->user = User::factory()->create();
        $this->ceg->users()->attach($this->user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($this->user);
    }

    public function test_az_e_szamlat_modellhivas_nelkul_olvassa_ki(): void
    {
        Http::fake();

        $dokumentum = $this->feltolt(XmlFixtura::ubl(), 'szamla.xml');

        app(Sorkezelo::class)->egyet($this->ceg);
        $dokumentum->refresh();

        // Ez az egész lépés értelme: a drága út érintetlen maradt.
        Http::assertNothingSent();

        $this->assertSame(DokumentumAllapot::EllenorzesreVar, $dokumentum->status);
        $this->assertSame('szamla', $dokumentum->doc_type->value);
        $this->assertSame('SZ-2026-0042', $dokumentum->doc_number);
        $this->assertSame('Példa Szállító Kft.', $dokumentum->supplier_name);
        $this->assertSame('100000.00', $dokumentum->net_amount);
        $this->assertSame('124800.00', $dokumentum->gross_amount);
        $this->assertSame('2026-03-14', $dokumentum->issue_date->toDateString());

        $this->assertSame([
            ['kulcs' => 27, 'kategoria' => 'S', 'netto' => '90000.00', 'afa' => '24300.00'],
            ['kulcs' => 5, 'kategoria' => 'S', 'netto' => '10000.00', 'afa' => '500.00'],
        ], $dokumentum->afa_bontas);
    }

    /**
     * A kiolvasás sora megmondja, mi olvasta ki. Prompt-verziót nem kaphat:
     * nem futott prompt, és a hamis verzió elrontaná az összehasonlítást,
     * amiért az oszlop van.
     */
    public function test_a_kiolvasas_sora_az_ertelmezot_nevezi_meg(): void
    {
        Http::fake();

        $dokumentum = $this->feltolt(XmlFixtura::cii(), 'factur-x.xml');
        app(Sorkezelo::class)->egyet($this->ceg);

        $kiolvasas = $dokumentum->refresh()->utolsoKiolvasas();

        $this->assertNotNull($kiolvasas);
        $this->assertSame('xml/cii', $kiolvasas->model);
        $this->assertNull($kiolvasas->prompt_version);
        $this->assertSame('0.000000', $kiolvasas->cost);
    }

    /** A strukturált adat sem ússza meg az ellenőrzést: a rossz összeg rossz. */
    public function test_a_validatorok_a_strukturalt_adatra_is_futnak(): void
    {
        Http::fake();

        $rossz = str_replace(
            '<cbc:TaxInclusiveAmount currencyID="HUF">124800.00</cbc:TaxInclusiveAmount>',
            '<cbc:TaxInclusiveAmount currencyID="HUF">999999.00</cbc:TaxInclusiveAmount>',
            XmlFixtura::ubl(),
        );

        $dokumentum = $this->feltolt($rossz, 'rossz.xml');
        app(Sorkezelo::class)->egyet($this->ceg);

        $bukott = (array) ($dokumentum->refresh()->utolsoKiolvasas()?->confidence['validators'] ?? []);

        $this->assertArrayHasKey('gross_amount', $bukott);
    }

    /**
     * Amit nem ismerünk fel, az nem vész el: megy a modellhez. A lánc
     * következő foka nem hibakezelés, hanem a rendes út.
     */
    public function test_az_ismeretlen_xml_a_modellhez_kerul(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $dokumentum = $this->feltolt(
            '<?xml version="1.0"?><SajatSzamla><Szam>X-1</Szam></SajatSzamla>',
            'sajat.xml',
        );

        app(Sorkezelo::class)->egyet($this->ceg);
        $dokumentum->refresh();

        Http::assertSentCount(1);
        $this->assertSame('X-1', $dokumentum->doc_number);
        $this->assertNotNull($dokumentum->utolsoKiolvasas()?->prompt_version);
    }

    /**
     * A böngésző az iratot azonos eredetű iframe-ben mutatja. Az XML-t ezért
     * soha nem adjuk ki XML típussal: egy beküldött `<?xml-stylesheet?>`
     * különben a mi nevünkben futtatna szkriptet.
     */
    public function test_az_xml_t_nem_xml_tipussal_szolgalja_ki(): void
    {
        $dokumentum = $this->feltolt(XmlFixtura::ubl(), 'szamla.xml');

        $valasz = $this->get(route('dokumentum.fajl', $dokumentum));

        $valasz->assertOk();
        $this->assertSame('text/plain; charset=UTF-8', $valasz->headers->get('Content-Type'));
        $this->assertSame('nosniff', $valasz->headers->get('X-Content-Type-Options'));
    }

    private function feltolt(string $tartalom, string $nev): Document
    {
        return app(FajlTarolo::class)->tarol(
            $this->ceg,
            $tartalom,
            $nev,
            'text/xml',
            'upload',
            $this->user->id,
        );
    }

    private function modellValasz(): array
    {
        return [
            'model' => 'teszt/modell-v1',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001],
            'choices' => [['message' => ['tool_calls' => [['function' => [
                'name' => 'record_extraction',
                'arguments' => json_encode([
                    'doc_type' => 'szamla',
                    'doc_number' => 'X-1',
                    'tobb_irat_gyanu' => false,
                    'confidence' => ['doc_number' => 0.9],
                ]),
            ]]]]]],
        ];
    }
}
