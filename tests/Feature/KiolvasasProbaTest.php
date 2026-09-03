<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixtura;
use Tests\TestCase;

/**
 * A parancssori próba. Akkor is működnie kell, amikor a webfelület nem érhető
 * el — éppen ezért létezik.
 */
final class KiolvasasProbaTest extends TestCase
{
    use RefreshDatabase;

    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->pdf = sys_get_temp_dir().'/proba-'.uniqid().'.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->pdf);
        parent::tearDown();
    }

    public function test_kiirja_amit_a_modell_felismert(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf])
            ->expectsOutputToContain('Számla')
            ->expectsOutputToContain('Példa Szállító Kft.')
            ->expectsOutputToContain('127 000')
            ->assertSuccessful();
    }

    /**
     * A bontás kiírása a próba egyik lényegi kérdésére felel: a modell
     * kulcsonként összesít-e, vagy tételsorokat sorol fel.
     */
    public function test_kiirja_az_afa_bontast(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        // Figyelem: két várt részlet nem lehet ugyanazon a kimeneti soron —
        // az `expectsOutputToContain` külön elvárásként regisztrálja mindet, és
        // az első illeszkedő elnyeli a sort a többi elől. A „Normál" ezért a
        // sor egyetlen állítása: ha az ott van, a sor rendben kiíródott.
        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf])
            ->expectsOutputToContain('ÁFA-bontás')
            ->expectsOutputToContain('Kategória')
            ->expectsOutputToContain('Normál')
            ->assertSuccessful();
    }

    /** A bukott ellenőrzést nem elég színnel jelezni — ki is kell mondani. */
    public function test_kiirja_a_bukott_ellenorzeseket(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf])
            ->expectsOutputToContain('ellenőrző számjegye nem stimmel')
            ->assertSuccessful();
    }

    /** A próba nem szemetel: alapból eltakarít maga után, és nem fogyaszt keretet. */
    public function test_alapbol_torli_a_probatetelt(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf])->assertSuccessful();

        $this->assertSame(0, Document::query()->withoutGlobalScopes()->count());
    }

    public function test_a_megtart_kapcsoloval_bent_marad(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf, '--megtart' => true])
            ->expectsOutputToContain('Beérkezőben maradt')
            ->assertSuccessful();

        $this->assertSame(1, Document::query()->withoutGlobalScopes()->count());
    }

    /**
     * Ez a parancs legfontosabb ága: ha a modellhívás elszáll, a hibát kell
     * megmutatnia, mert épp azért futtatjuk, hogy megtudjuk, mi a baj.
     */
    public function test_hiba_eseten_megmutatja_az_okot(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'nincs ilyen modell']], 400)]);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf])
            ->expectsOutputToContain('A kiolvasás nem sikerült')
            ->expectsOutputToContain('nincs ilyen modell')
            ->assertFailed();
    }

    /**
     * A webcímhez tartozó FTP-fiók a `public/`-ba lép be, tehát oda a
     * legkönnyebb feltölteni — és az a webgyökér. Egy valódi ügyfélszámla
     * onnan bárkinek letölthető, ezért ezt ki kell mondani. A kiolvasás
     * viszont attól még fusson le: a figyelmeztetés nem tiltás.
     */
    public function test_figyelmeztet_ha_a_fajl_a_webgyokerben_van(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $nyilvanos = public_path('proba-szamla.pdf');
        copy($this->pdf, $nyilvanos);

        try {
            $this->artisan('kiolvasas:proba', ['fajl' => $nyilvanos])
                ->expectsOutputToContain('bárki letöltheti')
                ->expectsOutputToContain('proba-szamla.pdf')
                ->expectsOutputToContain('Számla')
                ->assertSuccessful();
        } finally {
            @unlink($nyilvanos);
        }
    }

    public function test_nem_letezo_fajlt_elutasit(): void
    {
        $this->artisan('kiolvasas:proba', ['fajl' => '/nincs/ilyen.pdf'])->assertFailed();
    }

    /**
     * A cégnyitás a webfelületen történne — és épp az lehet elérhetetlen,
     * részben ezért van ez a parancs. Cég nélkül is működnie kell.
     */
    public function test_ceg_nelkul_ideiglenes_ceget_hasznal_es_eltakaritja(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $this->assertSame(0, Company::query()->count());

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf, '--ceg-nev' => 'Példa Kereskedelmi Kft.'])
            ->expectsOutputToContain('Példa Kereskedelmi Kft.')
            ->expectsOutputToContain('ideiglenes cég törölve')
            ->assertSuccessful();

        $this->assertSame(0, Company::query()->count(), 'Az ideiglenes cég nem maradhat bent.');
        $this->assertSame(0, Document::query()->withoutGlobalScopes()->count());
    }

    public function test_megtart_eseten_az_ideiglenes_ceg_bent_marad_de_szol_rola(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf, '--megtart' => true])
            ->expectsOutputToContain('cég is')
            ->assertSuccessful();

        $this->assertSame(1, Company::query()->count());
    }

    /**
     * Modell-összehasonlításhoz. Élesben a konfiguráció gyorsítótárazva van,
     * ezért környezeti változóval nem lehetne felülírni — csak így.
     */
    public function test_a_modell_kapcsolo_felulirja_a_beallitottat(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        config(['openrouter.model' => 'anthropic/claude-sonnet-5']);

        $this->artisan('kiolvasas:proba', ['fajl' => $this->pdf, '--modell' => 'anthropic/claude-haiku-4.5'])
            ->expectsOutputToContain('anthropic/claude-haiku-4.5')
            ->assertSuccessful();

        // A kérés is a felülírt modellel ment ki, nem csak a fejlécben látszik.
        Http::assertSent(fn ($keres) => $keres['model'] === 'anthropic/claude-haiku-4.5');
    }

    /**
     * A lánc lényege: ha a fájlban strukturált adat van, azt ki kell mondani —
     * azért ne fizessünk modellhívást, ami ingyen is megvan.
     */
    public function test_jelzi_ha_a_pdf_strukturalt_adatot_rejt(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $utvonal = sys_get_temp_dir().'/facturx-'.uniqid().'.pdf';
        file_put_contents($utvonal, PdfFixtura::beagyazottXmlLel(
            '<?xml version="1.0"?><Invoice><ID>DV-2025/1170</ID></Invoice>',
        ));

        try {
            $this->artisan('kiolvasas:proba', ['fajl' => $utvonal])
                ->expectsOutputToContain('PDF beágyazott XML-lel')
                ->expectsOutputToContain('modellhívás nélkül is kiolvasható')
                ->assertSuccessful();
        } finally {
            @unlink($utvonal);
        }
    }

    public function test_a_szovegreteget_felismeri_es_kiirja(): void
    {
        Company::factory()->create(['name' => 'Próba Kft.']);
        Http::fake(['*/chat/completions' => Http::response($this->modellValasz())]);

        $utvonal = sys_get_temp_dir().'/szoveges-'.uniqid().'.pdf';
        file_put_contents($utvonal, PdfFixtura::szovegreteggel());

        try {
            $this->artisan('kiolvasas:proba', ['fajl' => $utvonal])
                ->expectsOutputToContain('PDF szövegréteggel')
                ->assertSuccessful();
        } finally {
            @unlink($utvonal);
        }
    }

    private function modellValasz(): array
    {
        return [
            'model' => 'teszt/modell-v1',
            'usage' => ['prompt_tokens' => 2100, 'completion_tokens' => 320, 'cost' => 0.0091],
            'choices' => [[
                'message' => ['tool_calls' => [[
                    'function' => [
                        'name' => 'record_extraction',
                        'arguments' => json_encode([
                            'doc_type' => 'szamla',
                            'supplier_name' => 'Példa Szállító Kft.',
                            'supplier_tax_number' => '10773381-2-44',
                            'customer_tax_number' => '12345678-2-42',   // rossz ellenőrző számjegy
                            'doc_number' => 'SZ-2026-0042',
                            'issue_date' => '2026.03.14.',
                            'currency' => 'huf',
                            'net_amount' => '100 000',
                            'vat_amount' => '27 000',
                            'gross_amount' => '127 000',
                            'afa_bontas' => [
                                ['kulcs' => 27, 'kategoria' => 'S', 'netto' => '100 000', 'afa' => '27 000'],
                            ],
                            'tobb_irat_gyanu' => false,
                            'confidence' => ['doc_type' => 0.98, 'supplier_name' => 0.95, 'gross_amount' => 0.99],
                        ]),
                    ],
                ]]],
            ]],
        ];
    }
}
