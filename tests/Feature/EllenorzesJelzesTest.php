<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\Ellenorzes;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az ellenőrző képernyő jelzései.
 *
 * A kiemelés a **bajt** jelöli, nem a rendben lévőt: egy képernyő, ahol minden
 * ki van emelve, semmit nem emel ki. Ezért a „biztos" mező szándékosan a
 * szokásos szürke keretet kapja.
 */
final class EllenorzesJelzesTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceg = Company::factory()->create();
        $this->user = User::factory()->create();
        $this->ceg->users()->attach($this->user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($this->user);
    }

    /**
     * A bukott ellenőrzés önmagában pirosít.
     *
     * Két dolgot állít egyszerre. Egy: a determinisztikus jel nem a modell
     * magabiztosságán keresztül érkezik (korábban a 0,3-as plafonon át jutott a
     * képernyőre, vagyis a megbízható jel a megbízhatatlanon át) — itt a
     * magabiztosság szándékosan 1,0, mégis pirosnak kell lennie. Kettő: a jelzés
     * a **képernyőn lévő** értékre vonatkozik, nem a tárolt gépi verdiktre — a
     * fixtúra adószámának valóban rossz az ellenőrző számjegye, és ezt a
     * `render()`-ben újrafutó validátor állapítja meg, nem az adatbázis.
     */
    public function test_a_bukott_ellenorzes_akkor_is_pirosit_ha_a_modell_magabiztos(): void
    {
        $dokumentum = $this->dokumentum(combined: ['supplier_tax_number' => 1.0]);

        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum]);

        $this->assertSame('gyanus', $komponens->instance()->sav('supplier_tax_number'));
    }

    /** És ha az ember jó adószámra javítja, a piros elmúlik. */
    public function test_a_javitas_utan_elmulik_a_piros(): void
    {
        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $this->dokumentum()]);

        $komponens->set('mezok.supplier_tax_number', '11111111-2-42');

        $this->assertSame('nincs_adat', $komponens->instance()->sav('supplier_tax_number'));
    }

    /**
     * A hiányzó magabiztosság nem ugyanaz, mint a magas: az egyikért a modell
     * jótállt, a másikról semmit nem tudunk. Korábban a kettő egyformán
     * festett, és így egy néma modell ugyanolyan megnyugtatónak látszott.
     */
    public function test_a_nem_ertekelt_mezo_elkulonul_a_biztostol(): void
    {
        $dokumentum = $this->dokumentum(combined: ['doc_number' => 0.95]);

        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])->instance();

        $this->assertSame('biztos', $komponens->sav('doc_number'));
        $this->assertSame('nincs_adat', $komponens->sav('fizetendo'));
    }

    public function test_az_alacsony_magabiztossag_ellenorzesre_hiv(): void
    {
        $dokumentum = $this->dokumentum(combined: ['supplier_name' => 0.6, 'customer_name' => 0.2]);

        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])->instance();

        $this->assertSame('bizonytalan', $komponens->sav('supplier_name'));
        $this->assertSame('gyanus', $komponens->sav('customer_name'));
    }

    /** A jelmagyarázat ne ígérjen olyat, ami a mezőkön sosem jelenik meg. */
    public function test_a_jelmagyarazat_a_bajt_igeri_nem_a_rendben_levot(): void
    {
        $dokumentum = $this->dokumentum(combined: ['doc_number' => 1.0]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertSee('ellenőrzés bukott')
            ->assertSee('a modell bizonytalan')
            ->assertSee('nem nyilatkozott róla')
            // A zöld pötty azt ígérte, hogy a rendben lévő mező is kap színt —
            // pedig szándékosan nem kap. (A „biztos" szóra nem lehet állítani:
            // az a `mezo-biztos` osztálynévben is szerepel.)
            ->assertDontSee('bg-emerald-600');
    }

    /** @param  array<string, float>  $combined */
    private function dokumentum(array $combined = []): Document
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->ceg->id,
            'doc_number' => 'SZ-1',
            'supplier_name' => 'Példa Kft.',
            'customer_name' => 'Vevő Zrt.',
            'supplier_tax_number' => '12345678-2-42',
        ]);

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'model' => 'teszt/modell',
            'prompt_version' => 'teszt',
            // A tárolt `validators` a gépi verdikt, és marad is audit-nyomnak —
            // a képernyő viszont már nem ebből színez, hanem a jelenlegi
            // értékekből. Ezért itt szándékosan üres.
            'confidence' => ['model' => [], 'validators' => [], 'combined' => $combined],
        ]);
        $kiolvasas->company_id = $this->ceg->id;
        $kiolvasas->save();

        return $dokumentum;
    }
}
