<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\DocumentExtraction;
use App\Models\User;
use App\Services\Extraction\Sorkezelo;
use App\Services\Files\FajlTarolo;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Az idegen bizonylat a **tárolt** verdiktben.
 *
 * A képernyő a jelenlegi értékekből színez újra, a kiolvasás sora viszont a
 * gépi verdiktet őrzi: ebből derül ki utólag, mit látott a modell, és ez fogja
 * vissza a magabiztosságot is (`Konfidencia::BUKAS_PLAFON`). Ha az ellenőrzés
 * csak a képernyőn futna, a frissen beérkezett idegen irat a listában
 * ártatlannak látszana egészen addig, amíg valaki meg nem nyitja.
 *
 * Az OpenRouter-hívás mockolt: valódi pénzt egy teszt nem költhet.
 */
final class IdegenBizonylatTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ceg = Company::factory()->create(['tax_number' => '11176165-2-10']);
        $this->user = User::factory()->create();
        $this->ceg->users()->attach($this->user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($this->user);
    }

    public function test_a_kiolvasas_sora_megorzi_az_idegen_bizonylat_verdiktjet(): void
    {
        $kiolvasas = $this->kiolvas(szallito: '10773381-2-44', vevo: '12038538-2-41');

        $verdikt = (array) ($kiolvasas->confidence['validators'] ?? []);

        $this->assertArrayHasKey('customer_tax_number', $verdikt);
        $this->assertStringContainsString('nem a te cégednek', (string) $verdikt['customer_tax_number']);
    }

    /** A bukott ellenőrzés a tárolt magabiztosságot is visszafogja. */
    public function test_az_idegen_bizonylat_magabiztossaga_leszorul(): void
    {
        $kiolvasas = $this->kiolvas(szallito: '10773381-2-44', vevo: '12038538-2-41');

        $pont = (float) ($kiolvasas->confidence['combined']['customer_tax_number'] ?? 1.0);

        $this->assertLessThanOrEqual(
            (float) config('szamlafolyo.extraction.warn_threshold'),
            $pont,
            'A modell 0,93-as magabiztossága átment, pedig a bizonylat idegen.',
        );
    }

    /** A nekünk szóló számlán ugyanez a mező érintetlen marad. */
    public function test_a_nekunk_szolo_szamlan_nincs_verdikt(): void
    {
        $kiolvasas = $this->kiolvas(szallito: '10773381-2-44', vevo: '11176165-2-10');

        $verdikt = (array) ($kiolvasas->confidence['validators'] ?? []);

        $this->assertArrayNotHasKey('customer_tax_number', $verdikt);
    }

    private function kiolvas(string $szallito, string $vevo): DocumentExtraction
    {
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz($szallito, $vevo))]);

        $dokumentum = app(FajlTarolo::class)->tarol(
            $this->ceg,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
            'idegen.pdf',
            'application/pdf',
            'upload',
            $this->user->id,
        );

        app(Sorkezelo::class)->egyet($this->ceg);

        return DocumentExtraction::query()
            ->where('document_id', $dokumentum->id)
            ->latest('id')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function modellValasz(string $szallito, string $vevo): array
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
                                'supplier_tax_number' => $szallito,
                                'customer_name' => 'Vevő Zrt.',
                                'customer_tax_number' => $vevo,
                                'doc_number' => 'SZ-2026-0042',
                                'issue_date' => '2026-03-14',
                                'currency' => 'huf',
                                'net_amount' => '100 000',
                                'vat_amount' => '27 000',
                                'gross_amount' => '127 000',
                                'tobb_irat_gyanu' => false,
                                // Szándékosan magas: a determinisztikus jelnek a
                                // magabiztos modellt is le kell húznia.
                                'confidence' => ['customer_tax_number' => 0.93, 'supplier_tax_number' => 0.95],
                            ]),
                        ],
                    ]],
                ],
            ]],
        ];
    }
}
