<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumAllapot;
use App\Enums\Szerep;
use App\Livewire\App\Archivum;
use App\Livewire\App\Ellenorzes;
use App\Livewire\App\ExportKepernyo;
use App\Models\Company;
use App\Models\DocumentCorrection;
use App\Models\User;
use App\Services\Extraction\Sorkezelo;
use App\Services\Files\FajlHiba;
use App\Services\Files\FajlTarolo;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az egész termék egy tesztben: fájl be → AI kiolvassa → ember javít és
 * jóváhagy → export → az eredeti fájl törlődik → archívumból visszahívható.
 *
 * Az OpenRouter-hívás mockolt: valódi pénzt egy teszt nem költhet.
 */
final class TeljesFolyamatTest extends TestCase
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

    public function test_feltoltestol_az_archivumig(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->modellValasz()),
        ]);

        // 1. Feltöltés
        $dokumentum = app(FajlTarolo::class)->tarol(
            $this->ceg,
            $this->pdfTartalom(),
            'marciusi-szamla.pdf',
            'application/pdf',
            'upload',
            $this->user->id,
        );

        $this->assertSame(DokumentumAllapot::Feltoltve, $dokumentum->status);
        $this->assertTrue(Storage::disk('local')->exists($dokumentum->storage_path));

        // 2. Kiolvasás (ezt éles működésben a böngésző vagy a cron indítja)
        app(Sorkezelo::class)->egyet($this->ceg);
        $dokumentum->refresh();

        $this->assertSame(DokumentumAllapot::EllenorzesreVar, $dokumentum->status);
        $this->assertSame('szamla', $dokumentum->doc_type->value);
        $this->assertSame('Példa Szállító Kft.', $dokumentum->supplier_name);
        $this->assertSame('127000.00', $dokumentum->gross_amount);
        // A magyar írásmódú összeget a mi alakunkra hozta.
        $this->assertSame('100000.00', $dokumentum->net_amount);
        $this->assertSame('2026-03-14', $dokumentum->issue_date->toDateString());

        // A gépi válasz és a magabiztosság eltéve, a hibás adószám lehúzva.
        $kiolvasas = $dokumentum->utolsoKiolvasas();
        $this->assertNotNull($kiolvasas);
        $this->assertNotNull($kiolvasas->raw_response);
        $this->assertArrayHasKey('customer_tax_number', $kiolvasas->confidence['validators']);
        $this->assertLessThanOrEqual(0.3, $kiolvasas->confidence['combined']['customer_tax_number']);

        // 3. Ellenőrzés: az ember kijavítja a rossz vevő-adószámot
        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertSet('mezok.doc_number', 'SZ-2026-0042')
            ->set('mezok.customer_tax_number', '10537914-4-44')
            ->call('jovahagyas')
            ->assertHasNoErrors();

        $dokumentum->refresh();
        $this->assertSame(DokumentumAllapot::Jovahagyva, $dokumentum->status);
        $this->assertSame('10537914-4-44', $dokumentum->customer_tax_number);

        // A javítás mezőnként eltéve — ez a tanulást lehetővé tévő adat.
        $javitas = DocumentCorrection::query()->where('field', 'customer_tax_number')->first();
        $this->assertNotNull($javitas);
        $this->assertSame('12345678-2-42', $javitas->machine_value);
        $this->assertSame('10537914-4-44', $javitas->human_value);

        // 4. Export
        $utvonalExportElott = $dokumentum->storage_path;

        Livewire::test(ExportKepernyo::class)
            ->set('formatum', 'xlsx')
            ->assertSet('formatum', 'xlsx')
            ->call('exportal');

        $dokumentum->refresh();
        $this->assertSame(DokumentumAllapot::Exportalva, $dokumentum->status);
        $this->assertNotNull($dokumentum->export_id);

        // 5. Az eredeti fájl törlődött, az adat megmaradt
        $this->assertNull($dokumentum->storage_path);
        $this->assertNotNull($dokumentum->file_deleted_at);
        $this->assertFalse(Storage::disk('local')->exists($utvonalExportElott));
        $this->assertSame('127000.00', $dokumentum->gross_amount);

        // Az export fájlja viszont megvan és letölthető
        $export = $dokumentum->export;
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));
        $this->get(route('export.letoltes', $export))->assertOk();

        // 6. Archívumból visszahívás
        Livewire::test(Archivum::class)->call('visszahiv', $dokumentum->id);

        $dokumentum->refresh();
        $this->assertSame(DokumentumAllapot::Jovahagyva, $dokumentum->status);
        $this->assertNull($dokumentum->export_id);
    }

    public function test_ugyanaz_a_fajl_masodszor_duplikatum_lesz(): void
    {
        $elso = app(FajlTarolo::class)->tarol($this->ceg, $this->pdfTartalom(), 'a.pdf', 'application/pdf');
        $masodik = app(FajlTarolo::class)->tarol($this->ceg, $this->pdfTartalom(), 'a-masolat.pdf', 'application/pdf');

        $this->assertSame(DokumentumAllapot::Feltoltve, $elso->status);
        $this->assertSame(DokumentumAllapot::Duplikatum, $masodik->status);
        $this->assertSame($elso->id, $masodik->duplicate_of_id);
    }

    public function test_nem_tamogatott_fajltipust_elutasit(): void
    {
        $this->expectException(FajlHiba::class);

        app(FajlTarolo::class)->tarol($this->ceg, 'sima szöveg', 'jegyzet.txt', 'text/plain');
    }

    /** A modell válasza szándékosan magyar írásmódú összeggel és egy rossz adószámmal. */
    private function modellValasz(): array
    {
        return [
            'model' => 'teszt/modell-v1',
            'usage' => ['prompt_tokens' => 2100, 'completion_tokens' => 320, 'cost' => 0.0091],
            'choices' => [[
                'message' => [
                    'tool_calls' => [[
                        'function' => [
                            'name' => 'record_extraction',
                            'arguments' => json_encode([
                                'doc_type' => 'szamla',
                                'supplier_name' => 'Példa Szállító Kft.',
                                'supplier_tax_number' => '10773381-2-44',
                                'customer_name' => 'Vevő Zrt.',
                                'customer_tax_number' => '12345678-2-42',  // rossz ellenőrző számjegy
                                'doc_number' => 'SZ-2026-0042',
                                'issue_date' => '2026.03.14.',
                                'due_date' => '2026-03-28',
                                'currency' => 'huf',
                                'net_amount' => '100 000',
                                'vat_amount' => '27 000',
                                'gross_amount' => '127 000',
                                'payment_method' => 'átutalás',
                                'tobb_irat_gyanu' => false,
                                'confidence' => [
                                    'doc_type' => 0.98,
                                    'supplier_name' => 0.95,
                                    'customer_tax_number' => 0.93,
                                    'doc_number' => 0.97,
                                    'gross_amount' => 0.99,
                                ],
                            ]),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    /** Valódi PDF-fejléc, hogy a tartalomból megállapított MIME-típus stimmeljen. */
    private function pdfTartalom(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }
}
