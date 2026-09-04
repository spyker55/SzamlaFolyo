<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumAllapot;
use App\Enums\Szerep;
use App\Livewire\App\Ellenorzes;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCorrection;
use App\Models\DocumentExtraction;
use App\Models\User;
use App\Services\Billing\Kvota;
use App\Services\Documents\AthelyezesHiba;
use App\Services\Documents\DokumentumAthelyezes;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az irat átvitele a felhasználó másik cégéhez.
 *
 * Ez az **egyetlen** művelet, ami átlép a bérlőhatáron, ezért a tesztek fele
 * nem arról szól, hogy működik, hanem arról, hogy mikor **nem** hajlandó
 * működni. A bérlő-elkülönítés minden más ponton abszolút; ha ez a művelet
 * kilyukad, azon a lyukon a `BerloElkulonitesTest` egész garanciája kifolyik.
 */
final class DokumentumAthelyezesTest extends TestCase
{
    use RefreshDatabase;

    private const MIENK = '11176165-2-10';

    private const MASIK_CEGUNK = '10773381-2-44';

    private const IDEGEN = '12038538-2-41';

    private User $user;

    private Company $alfa;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->alfa = Company::factory()->create(['name' => 'Alfa Kft.', 'tax_number' => self::MIENK]);
        $this->beta = Company::factory()->create(['name' => 'Béta Bt.', 'tax_number' => self::MASIK_CEGUNK]);

        $this->tag($this->alfa, Szerep::Tulajdonos);
        $this->tag($this->beta, Szerep::Tulajdonos);

        app(Berlo::class)->beallit($this->alfa);
        $this->actingAs($this->user);
    }

    private function tag(Company $ceg, Szerep $szerep, ?User $ki = null): void
    {
        $ceg->users()->attach(($ki ?? $this->user)->id, ['role' => $szerep->value, 'accepted_at' => now()]);
    }

    /** Alfánál fekvő irat, ami a szállító szerint Bétáé (vevő: idegen). */
    private function betahozTartozo(array $extra = []): Document
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->alfa->id,
            'original_filename' => 'atviendo.pdf',
            'supplier_tax_number' => self::IDEGEN,
            'customer_tax_number' => self::MASIK_CEGUNK,
            'storage_path' => 'iratok/'.$this->alfa->id.'/1/atviendo.pdf',
            'sha256' => hash('sha256', 'atviendo'),
            ...$extra,
        ]);

        Storage::disk('local')->put((string) $dokumentum->storage_path, 'PDF');

        return $dokumentum;
    }

    // — 1. A felismerés ——————————————————————————————————————

    public function test_a_kepernyo_felajanlja_a_masik_ceget(): void
    {
        Livewire::test(Ellenorzes::class, ['dokumentum' => $this->betahozTartozo()])
            ->assertSee('Béta Bt.')
            ->assertSee('az is a te céged');
    }

    /** A helyén lévő iratnál a felajánlott áthelyezés maga volna a hiba. */
    public function test_a_helyen_levo_iratot_nem_ajanlja_athelyezni(): void
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->alfa->id,
            'supplier_tax_number' => self::IDEGEN,
            'customer_tax_number' => self::MIENK,
        ]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertDontSee('az is a te céged');
    }

    /** Idegen adószámra nincs mit ajánlani — a piros jelzés marad, magában. */
    public function test_idegen_adoszamra_nincs_ajanlas(): void
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->alfa->id,
            'supplier_tax_number' => self::IDEGEN,
            'customer_tax_number' => self::IDEGEN,
        ]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertSee('nem a te cégednek szól')
            ->assertDontSee('az is a te céged');
    }

    /**
     * Hibás ellenőrző számjegyű adószámra nem építünk következtetést — se
     * vádat, se javaslatot. Egy félreolvasott számjegy nem irányíthat át egy
     * bizonylatot egy másik céghez.
     */
    public function test_a_rossz_adoszamra_nem_ajanl_ceget(): void
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->alfa->id,
            'supplier_tax_number' => self::MASIK_CEGUNK,
            'customer_tax_number' => '12345678-2-42',
        ]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertDontSee('az is a te céged');
    }

    // — 2. Az áthelyezés ——————————————————————————————————————

    public function test_az_athelyezes_mindent_atvisz(): void
    {
        $dokumentum = $this->betahozTartozo();
        $regiUtvonal = (string) $dokumentum->storage_path;

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id, 'prompt_version' => 'teszt', 'credits' => 3,
        ]);
        $kiolvasas->company_id = $this->alfa->id;
        $kiolvasas->save();

        $javitas = new DocumentCorrection([
            'document_id' => $dokumentum->id, 'field' => 'doc_number',
            'machine_value' => 'A', 'human_value' => 'B',
        ]);
        $javitas->company_id = $this->alfa->id;
        $javitas->save();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->call('athelyez')
            ->assertHasNoErrors()
            ->assertRedirect(route('beerkezo', absolute: false));

        $this->assertSame($this->beta->id, $dokumentum->fresh()?->company_id);
        $this->assertSame($this->beta->id, $kiolvasas->fresh()?->company_id);
        $this->assertSame($this->beta->id, $javitas->fresh()?->company_id);

        // A fájl is költözik: az útvonal a cég azonosítója alatt van, és a
        // forráscég tárhelyszámlálója sem viheti tovább a másik cég iratát.
        $ujUtvonal = (string) $dokumentum->fresh()?->storage_path;
        $this->assertStringStartsWith('iratok/'.$this->beta->id.'/', $ujUtvonal);
        $this->assertTrue(Storage::disk('local')->exists($ujUtvonal));
        $this->assertFalse(Storage::disk('local')->exists($regiUtvonal));
    }

    /** A keret az irattal együtt mozog: ez pénz, nem statisztika. */
    public function test_a_kredit_is_atkerul(): void
    {
        $dokumentum = $this->betahozTartozo();

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id, 'prompt_version' => 'teszt', 'credits' => 4,
        ]);
        $kiolvasas->company_id = $this->alfa->id;
        $kiolvasas->save();

        $this->assertSame(4, (new Kvota($this->alfa))->felhasznalt());

        app(DokumentumAthelyezes::class)->athelyez($dokumentum, $this->beta, $this->user);

        $this->assertSame(0, (new Kvota($this->alfa->fresh()))->felhasznalt());
        $this->assertSame(4, (new Kvota($this->beta->fresh()))->felhasznalt());
    }

    public function test_mindket_cegben_nyoma_marad(): void
    {
        app(DokumentumAthelyezes::class)->athelyez($this->betahozTartozo(), $this->beta, $this->user);

        $naplo = ActivityLog::query()->withoutGlobalScopes()->get();

        $this->assertSame(
            $this->alfa->id,
            $naplo->firstWhere('action', 'dokumentum.elvitte')?->company_id,
        );
        $this->assertSame(
            $this->beta->id,
            $naplo->firstWhere('action', 'dokumentum.erkezett')?->company_id,
        );
    }

    // — 3. Amikor nem hajlandó ————————————————————————————————

    /** A legfontosabb: idegen cégbe nem lehet iratot betenni. */
    public function test_idegen_cegbe_nem_lehet_athelyezni(): void
    {
        $idegenCeg = Company::factory()->create(['name' => 'Idegen Zrt.', 'tax_number' => self::IDEGEN]);
        $dokumentum = $this->betahozTartozo();

        $this->expectException(AthelyezesHiba::class);
        $this->expectExceptionMessage('mindkét cégben szerkesztési jog kell');

        app(DokumentumAthelyezes::class)->athelyez($dokumentum, $idegenCeg, $this->user);
    }

    /** Megtekintőként sem — a tagság önmagában kevés. */
    public function test_megtekintokent_nem_lehet_athelyezni(): void
    {
        $nezelodo = User::factory()->create();
        $this->tag($this->alfa, Szerep::Szerkeszto, $nezelodo);
        $this->tag($this->beta, Szerep::Megtekinto, $nezelodo);

        $this->expectException(AthelyezesHiba::class);

        app(DokumentumAthelyezes::class)->athelyez($this->betahozTartozo(), $this->beta, $nezelodo);
    }

    /**
     * Jóváhagyott iratot nem viszünk el: az már a forráscég könyvelése.
     * Utólag, észrevétlenül megváltoztatni rosszabb, mint nem engedni.
     */
    public function test_a_jovahagyott_iratot_nem_viszi_el(): void
    {
        $dokumentum = $this->betahozTartozo(['status' => DokumentumAllapot::Jovahagyva->value]);

        $this->expectException(AthelyezesHiba::class);
        $this->expectExceptionMessage('Csak ellenőrzés előtt álló iratot');

        app(DokumentumAthelyezes::class)->athelyez($dokumentum, $this->beta, $this->user);
    }

    /** A duplikátumok közös fájlon osztoznak: az egyik elvitele a másikat rontaná el. */
    public function test_a_duplikalt_iratot_nem_viszi_el(): void
    {
        $dokumentum = $this->betahozTartozo();

        $masolat = Document::factory()->create([
            'company_id' => $this->alfa->id,
            'status' => DokumentumAllapot::Duplikatum->value,
            'duplicate_of_id' => $dokumentum->id,
            'storage_path' => $dokumentum->storage_path,
        ]);

        $this->assertNotNull($masolat->id);
        $this->expectException(AthelyezesHiba::class);
        $this->expectExceptionMessage('közös fájlon osztoznak');

        app(DokumentumAthelyezes::class)->athelyez($dokumentum, $this->beta, $this->user);
    }

    /** Ha a célcégnél már bent van ugyanaz a fájl, nem csinálunk belőle kettőt. */
    public function test_a_celcegnel_mar_meglevo_iratot_nem_viszi_at(): void
    {
        $dokumentum = $this->betahozTartozo();

        Document::factory()->create([
            'company_id' => $this->beta->id,
            'sha256' => $dokumentum->sha256,
        ]);

        $this->expectException(AthelyezesHiba::class);
        $this->expectExceptionMessage('már bent van');

        app(DokumentumAthelyezes::class)->athelyez($dokumentum, $this->beta, $this->user);
    }

    /** Az elutasítás a képernyőn is látszik, nem néma 403-ként. */
    public function test_az_elutasitas_indoka_a_kepernyore_kerul(): void
    {
        $dokumentum = $this->betahozTartozo();

        Document::factory()->create(['company_id' => $this->beta->id, 'sha256' => $dokumentum->sha256]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->call('athelyez')
            ->assertHasErrors('athelyezes');

        $this->assertSame($this->alfa->id, $dokumentum->fresh()?->company_id);
    }
}
