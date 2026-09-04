<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\ExportKepernyo;
use App\Models\Company;
use App\Models\Document;
use App\Models\Export;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ügyfelenkénti export.
 *
 * Ez az a képesség, ami miatt egy könyvelőiroda **egyetlen** fiókban tudja
 * feldolgozni az összes ügyfelét: a szétválasztás az exportnál történik, nem
 * cégek adminisztrálásával. Ha ez elromlik, a termék visszakerül oda, hogy a
 * felhasználónak fogalmakat kell kezelnie ahhoz, hogy szétválasszon valamit.
 *
 * A szűrő **adószámra** megy, nem névre: a „Példa Kft.", „Példa Kft" és
 * „PÉLDA KFT" ugyanaz a cég, három sztring.
 */
final class ExportUgyfelSzuroTest extends TestCase
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

    private function irat(array $ertekek): Document
    {
        return Document::factory()->jovahagyva()->create([
            'company_id' => $this->ceg->id,
            'export_id' => null,
            ...$ertekek,
        ]);
    }

    public function test_az_ugyfelre_szurve_csak_az_o_iratai_mennek(): void
    {
        $this->irat(['customer_tax_number' => '11176165-2-10', 'doc_number' => 'ELSO-1']);
        $this->irat(['customer_tax_number' => '11176165-2-10', 'doc_number' => 'ELSO-2']);
        $this->irat(['customer_tax_number' => '10773381-2-44', 'doc_number' => 'MASIK-1']);

        Livewire::test(ExportKepernyo::class)
            ->assertSet('ugyfel', '')
            ->assertSee('3 tétel kerül exportba')
            ->set('ugyfel', '11176165')
            ->assertSee('2 tétel kerül exportba');
    }

    /**
     * A törzsszám dönt, nem a leírt alak. Ugyanaz a cég szerepelhet
     * „11176165-2-10" és „HU11176165" alakban is ugyanabban a hónapban — az
     * ÁFA-kód és a megyekód változhat, az adóalanyt az első nyolc jegy
     * azonosítja.
     */
    public function test_a_torzsszam_dont_nem_a_leirt_alak(): void
    {
        $this->irat(['customer_tax_number' => '11176165-2-10']);
        $this->irat(['customer_tax_number' => 'HU11176165']);
        $this->irat(['customer_tax_number' => '11176165-4-13']);

        Livewire::test(ExportKepernyo::class)
            ->set('ugyfel', '11176165')
            ->assertSee('3 tétel kerül exportba');
    }

    /**
     * A könyvelő ügyfele a bejövő számlán a vevő, a kimenőn a szállító —
     * ugyanannak az ügyfélnek a papírjai. Aki az ügyfelét választja, mindkettőt
     * várja, nem a felét.
     */
    public function test_az_ugyfel_kimeno_szamlai_is_bekerulnek(): void
    {
        $this->irat(['customer_tax_number' => '11176165-2-10']);
        $this->irat(['supplier_tax_number' => '11176165-2-10', 'customer_tax_number' => '10773381-2-44']);

        Livewire::test(ExportKepernyo::class)
            ->set('ugyfel', '11176165')
            ->assertSee('2 tétel kerül exportba');
    }

    /**
     * A választható lista a **vevő** oldalról áll össze. A szállítókat is
     * felvenni azt jelentené, hogy minden beszállító megjelenik benne — a
     * lista használhatatlanul hosszú lenne, és nem az ügyfeleket mutatná.
     */
    public function test_a_lista_a_vevo_oldalrol_all_ossze(): void
    {
        $this->irat(['customer_tax_number' => '11176165-2-10', 'customer_name' => 'Ügyfél Kft.']);
        $this->irat(['supplier_tax_number' => '10773381-2-44', 'supplier_name' => 'Beszállító Zrt.',
            'customer_tax_number' => '11176165-2-10', 'customer_name' => 'Ügyfél Kft.']);

        Livewire::test(ExportKepernyo::class)
            ->assertSee('Ügyfél Kft. (11176165-2-10)')
            ->assertDontSee('Beszállító Zrt.');
    }

    /** Az időszakon kívüli irat az ügyféllistába se kerüljön bele. */
    public function test_a_lista_az_idoszakot_koveti(): void
    {
        $regi = $this->irat(['customer_tax_number' => '11176165-2-10', 'customer_name' => 'Tavalyi Kft.']);
        $regi->forceFill(['created_at' => now()->subYear()])->save();

        $this->irat(['customer_tax_number' => '10773381-2-44', 'customer_name' => 'Idei Kft.']);

        Livewire::test(ExportKepernyo::class)
            ->assertSee('Idei Kft.')
            ->assertDontSee('Tavalyi Kft.');
    }

    /** Az elkészült export megőrzi, kire szűrtünk — utólag ez a bizonyíték. */
    public function test_az_export_megorzi_a_szurot(): void
    {
        $this->irat(['customer_tax_number' => '11176165-2-10']);
        $this->irat(['customer_tax_number' => '10773381-2-44']);

        Livewire::test(ExportKepernyo::class)
            ->set('ugyfel', '11176165')
            ->call('exportal');

        $export = Export::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $export->item_count);
        $this->assertSame('11176165', $export->filters['ugyfel'] ?? null);
    }
}
