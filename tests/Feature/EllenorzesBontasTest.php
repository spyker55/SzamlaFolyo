<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumTipus;
use App\Enums\Szerep;
use App\Livewire\App\Ellenorzes;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCorrection;
use App\Models\DocumentExtraction;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az ÁFA-bontás szerkesztése az ellenőrző képernyőn.
 *
 * Miért szerkeszthető egyáltalán: a sorok összege a legerősebb determinisztikus
 * jelünk — ez fogja meg azt a hibát, amikor a modell egy tételsor összegét írja
 * be végösszegnek. Ha viszont az embernek nincs mivel javítania, a jelzés csak
 * bosszantás lenne, a javított adat pedig úgysem jutna el a könyvelőhöz.
 */
final class EllenorzesBontasTest extends TestCase
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

    public function test_sort_lehet_hozzaadni_es_torolni(): void
    {
        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $this->dokumentum()]);

        $this->assertCount(2, $komponens->get('bontas'));

        $komponens->call('sorHozzaad');
        $this->assertCount(3, $komponens->get('bontas'));

        $komponens->call('sorTorol', 0);
        $bontas = $komponens->get('bontas');

        $this->assertCount(2, $bontas);
        // A törlés után a lista újraindexelődik, és a maradék sorok a helyükön
        // vannak — nem az első sor értékei csúsznak lejjebb.
        $this->assertSame('5', $bontas[0]['kulcs']);
    }

    /** A jóváhagyott bontás abban az alakban kerül az oszlopba, amiben tároljuk. */
    public function test_a_jovahagyas_menti_a_szerkesztett_bontast(): void
    {
        $dokumentum = $this->dokumentum();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->set('bontas.1.netto', '500')
            ->set('bontas.1.afa', '25')
            ->set('bontas.1.kategoria', 'S')
            ->call('jovahagyas')
            ->assertHasNoErrors();

        // Laza egyezés, szándékosan: a `json_encode(27.0)` „27"-et ír, tehát az
        // egész kulcs int-ként jön vissza az oszlopból. Szigorú egyezésre
        // állítani azt jelentené, hogy egy tárolási részletet teszünk
        // szabállyá.
        $this->assertEquals([
            ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '270.00'],
            ['kulcs' => 5.0, 'kategoria' => 'S', 'netto' => '500.00', 'afa' => '25.00'],
        ], $dokumentum->fresh()->afa_bontas);
    }

    /** Az ember úgy gépel, ahogy a papíron látja — nem ahogy tárolni fogjuk. */
    public function test_a_magyar_irasmodot_is_erti(): void
    {
        $dokumentum = $this->dokumentum();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->set('bontas.0.kulcs', '27%')
            ->set('bontas.0.netto', '1 000,50')
            ->call('jovahagyas')
            ->assertHasNoErrors();

        $this->assertSame('1000.50', $dokumentum->fresh()->afa_bontas[0]['netto']);
    }

    /**
     * Amit nem értünk, az megállít. Csendben nullát menteni rosszabb, mint
     * visszakérdezni — a bizonylat összege nem találgatás tárgya.
     */
    public function test_az_ertelmezhetetlen_bemenet_megallitja_a_jovahagyast(): void
    {
        $dokumentum = $this->dokumentum();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->set('bontas.0.kulcs', 'huszonhét')
            ->call('jovahagyas')
            ->assertHasErrors('bontas.0.kulcs')
            // A hiba nem elég, ha néma: a `mezo-jelzes` komponens a `mezok.`
            // előtaggal keres, a bontás hibái viszont nem oda kerülnek — ezért
            // olvassa a nézet közvetlenül a hibazsákot, és ezért van rá teszt.
            ->assertSee('Az ÁFA-kulcsot százalékban kell megadni.');

        $this->assertSame(DokumentumTipus::Szamla, $dokumentum->fresh()->doc_type);
        $this->assertNull($dokumentum->fresh()->approved_at);
    }

    /** A teljesen üres sor némán kiesik: a törléshez ne kelljen gombot keresni. */
    public function test_az_ures_sor_nem_hiba_csak_kiesik(): void
    {
        $dokumentum = $this->dokumentum();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->call('sorHozzaad')
            ->call('jovahagyas')
            ->assertHasNoErrors();

        $this->assertCount(2, $dokumentum->fresh()->afa_bontas);
    }

    /**
     * A javítás nyoma. A bontás nincs a `Sema::MEZOK` között (az a ciklus
     * skalárt feltételez), ezért külön kerül be — de **egyetlen** sorként, nem
     * mezőnként.
     */
    public function test_a_szerkesztett_bontas_egyetlen_javitaskent_kerul_be(): void
    {
        $dokumentum = $this->dokumentum();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->set('bontas.0.netto', '1200')
            ->call('jovahagyas')
            ->assertHasNoErrors();

        $javitasok = DocumentCorrection::query()->where('field', 'afa_bontas')->get();

        $this->assertCount(1, $javitasok);
        $this->assertStringContainsString('1200.00', (string) $javitasok->first()->human_value);
        $this->assertStringContainsString('1000.00', (string) $javitasok->first()->machine_value);
    }

    /** Amit nem nyúlt hozzá, arról ne állítsuk, hogy javította. */
    public function test_az_erintetlen_bontas_nem_szul_javitast(): void
    {
        Livewire::test(Ellenorzes::class, ['dokumentum' => $this->dokumentum()])
            ->call('jovahagyas')
            ->assertHasNoErrors();

        $this->assertSame(0, DocumentCorrection::query()->where('field', 'afa_bontas')->count());
    }

    /**
     * Ez a lényeg: **a jelzés a képernyőn lévő értékekről szól.**
     *
     * Korábban a kiolvasás sorában tárolt verdiktet mutattuk, ami a gépi
     * értékekről szólt — a javított mező pirosan maradt volna, a frissen
     * elrontott meg tisztán. A bizonylat itt eleve ellentmondásos: a bontás
     * sorai 1500-at adnak ki, a fejléc 5000-et.
     */
    public function test_a_piros_jelzes_eltunik_amint_az_ember_kijavitja(): void
    {
        $dokumentum = $this->dokumentum(['net_amount' => '5000.00', 'vat_amount' => '1240.00', 'gross_amount' => '6240.00']);

        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum]);

        $this->assertSame('gyanus', $komponens->instance()->sav('afa_bontas'));
        $this->assertSame('gyanus', $komponens->instance()->sav('net_amount'));

        // Az ember a papírról látja, hogy a 27%-os adóalap valójában 4500 —
        // ezzel a sorok 5000/1240-et adnak ki, pontosan a fejléc szerint.
        $komponens->set('bontas.0.netto', '4500')->set('bontas.0.afa', '1215');

        $this->assertArrayNotHasKey('afa_bontas', $komponens->instance()->validatorHibak);
        $this->assertArrayNotHasKey('net_amount', $komponens->instance()->validatorHibak);
    }

    /** És fordítva: ami eddig rendben volt, elromolhat az ember keze alatt. */
    public function test_a_frissen_elrontott_sor_azonnal_pirosodik(): void
    {
        $komponens = Livewire::test(Ellenorzes::class, ['dokumentum' => $this->dokumentum()]);

        $this->assertArrayNotHasKey('afa_bontas', $komponens->instance()->validatorHibak);

        $komponens->set('bontas.0.afa', '900');

        $this->assertSame('gyanus', $komponens->instance()->sav('afa_bontas'));
    }

    /**
     * A bontás gépi verdiktje a kiolvasás sorában marad — az az audit-nyom,
     * abból derül ki utólag, mit hibázott a modell. A képernyő az élőt mutatja,
     * a kettőnek nem szabad összekeverednie.
     */
    public function test_a_tarolt_gepi_verdikt_erintetlen_marad(): void
    {
        $dokumentum = $this->dokumentum();
        $kiolvasas = $dokumentum->utolsoKiolvasas();

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->set('bontas.0.netto', '1200')
            ->call('jovahagyas');

        $this->assertSame(
            $kiolvasas->confidence,
            $kiolvasas->fresh()->confidence,
        );
    }

    /** @param  array<string, mixed>  $felulir */
    private function dokumentum(array $felulir = []): Document
    {
        $bontas = [
            ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '270.00'],
            ['kulcs' => 5.0, 'kategoria' => 'S', 'netto' => '500.00', 'afa' => '25.00'],
        ];

        $dokumentum = Document::factory()->ellenorzesreVar()->create($felulir + [
            'company_id' => $this->ceg->id,
            'doc_type' => DokumentumTipus::Szamla->value,
            'doc_number' => 'SZ-1',
            'supplier_name' => 'Példa Kft.',
            'currency' => 'HUF',
            'net_amount' => '1500.00',
            'vat_amount' => '295.00',
            'gross_amount' => '1795.00',
            'afa_bontas' => $bontas,
        ]);

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'model' => 'teszt/modell',
            'prompt_version' => 'teszt',
            'fields' => ['afa_bontas' => $bontas],
            'confidence' => ['model' => [], 'validators' => [], 'combined' => []],
        ]);
        $kiolvasas->company_id = $this->ceg->id;
        $kiolvasas->save();

        return $dokumentum;
    }
}
