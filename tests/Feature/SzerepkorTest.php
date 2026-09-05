<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Enums\Szerep;
use App\Livewire\App\Archivum;
use App\Livewire\App\Beerkezo;
use App\Livewire\App\Ellenorzes;
use App\Livewire\App\ExportKepernyo;
use App\Livewire\App\Tetelek;
use App\Models\Company;
use App\Models\Document;
use App\Models\Export;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mit tehet a három szerepkör.
 *
 * Ez a teszt egy valódi hiányból született: a `Szerep::szerkeszthet()` létezett
 * és le is volt dokumentálva, de **egyetlen hívója sem volt**. Aki Megtekintőként
 * kapott meghívót, az feltölthetett, jóváhagyhatott, exportálhatott és
 * véglegesen törölhetett — vagyis a szerepkör csak a tagok listáján látszott,
 * a rendszerben nem jelentett semmit.
 *
 * A korlátot a **műveletben** kell megfogni, nem a képernyőn elrejtett gombbal:
 * egy Livewire-akció közvetlenül is meghívható. A nézet elrejtése udvariasság,
 * ez a teszt a zár.
 */
final class SzerepkorTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceg = Company::factory()->create();
        app(Berlo::class)->beallit($this->ceg);
    }

    private function belep(Szerep $szerep): User
    {
        $user = User::factory()->create();
        $this->ceg->users()->attach($user->id, [
            'role' => $szerep->value,
            'accepted_at' => now(),
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function irat(DokumentumAllapot $allapot): Document
    {
        return Document::factory()->create([
            'company_id' => $this->ceg->id,
            'status' => $allapot->value,
            'doc_type' => DokumentumTipus::Szamla->value,
            'doc_number' => 'SZ-1',
            'supplier_name' => 'Példa Kft.',
            'currency' => 'HUF',
            'net_amount' => '1000.00',
            'vat_amount' => '270.00',
            'gross_amount' => '1270.00',
        ]);
    }

    // ---------------------------------------------------------------- Megtekintő

    public function test_a_megtekinto_nem_torolhet_a_beerkezobol(): void
    {
        $this->belep(Szerep::Megtekinto);
        $irat = $this->irat(DokumentumAllapot::EllenorzesreVar);

        Livewire::test(Beerkezo::class)->call('torol', $irat->id)->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $irat->id]);
    }

    public function test_a_megtekinto_nem_inditja_ujra_a_hibas_iratot(): void
    {
        $this->belep(Szerep::Megtekinto);
        $irat = $this->irat(DokumentumAllapot::Hiba);

        Livewire::test(Beerkezo::class)->call('ujra', $irat->id)->assertForbidden();

        $this->assertSame(DokumentumAllapot::Hiba, $irat->fresh()->status);
    }

    public function test_a_megtekinto_nem_hagyhat_jova(): void
    {
        $this->belep(Szerep::Megtekinto);
        $irat = $this->irat(DokumentumAllapot::EllenorzesreVar);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $irat])
            ->call('jovahagyas')
            ->assertForbidden();

        $this->assertSame(DokumentumAllapot::EllenorzesreVar, $irat->fresh()->status);
    }

    public function test_a_megtekinto_nem_kuldhet_javitasra(): void
    {
        $this->belep(Szerep::Megtekinto);
        $irat = $this->irat(DokumentumAllapot::Jovahagyva);

        Livewire::test(Tetelek::class)->call('javitasra', $irat->id)->assertForbidden();

        $this->assertSame(DokumentumAllapot::Jovahagyva, $irat->fresh()->status);
    }

    public function test_a_megtekinto_nem_exportalhat(): void
    {
        $this->belep(Szerep::Megtekinto);
        $this->irat(DokumentumAllapot::Jovahagyva);

        Livewire::test(ExportKepernyo::class)->call('exportal')->assertForbidden();

        $this->assertDatabaseCount('exports', 0);
    }

    public function test_a_megtekinto_nem_hivhat_vissza_az_archivumbol(): void
    {
        $this->belep(Szerep::Megtekinto);
        $irat = $this->irat(DokumentumAllapot::Exportalva);

        Livewire::test(Archivum::class)->call('visszahiv', $irat->id)->assertForbidden();

        $this->assertSame(DokumentumAllapot::Exportalva, $irat->fresh()->status);
    }

    /** Az irat megnézése viszont a dolga — a szerepkör nem falazza ki a képernyőről. */
    public function test_a_megtekinto_megnyithatja_az_ellenorzest(): void
    {
        $this->belep(Szerep::Megtekinto);
        $irat = $this->irat(DokumentumAllapot::EllenorzesreVar);

        $this->get(route('ellenorzes', $irat))
            ->assertOk()
            ->assertSee('Megtekintőként nem tudod jóváhagyni');
    }

    // ---------------------------------------------------------------- Szerkesztő

    public function test_a_szerkeszto_jovahagyhat(): void
    {
        $this->belep(Szerep::Szerkeszto);
        $irat = $this->irat(DokumentumAllapot::EllenorzesreVar);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $irat])
            ->call('jovahagyas')
            ->assertOk();

        $this->assertSame(DokumentumAllapot::Jovahagyva, $irat->fresh()->status);
    }

    /**
     * A végleges törlés viszont nem az övé. A `Szerep::adminisztralhat()` ezt
     * eddig is kimondta — csak semmi nem kényszerítette ki.
     */
    public function test_a_szerkeszto_nem_torolhet_veglegesen(): void
    {
        $this->belep(Szerep::Szerkeszto);
        $irat = $this->irat(DokumentumAllapot::Exportalva);

        Livewire::test(Archivum::class)->call('tetelTorles', $irat->id)->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $irat->id]);
    }

    public function test_a_szerkeszto_nem_torolhet_exportot(): void
    {
        $this->belep(Szerep::Szerkeszto);
        $export = Export::create([
            'company_id' => $this->ceg->id,
            'format' => 'csv',
            'file_name' => 'export.csv',
        ]);

        Livewire::test(Archivum::class)->call('exportTorles', $export->id)->assertForbidden();

        $this->assertDatabaseHas('exports', ['id' => $export->id]);
    }

    // ---------------------------------------------------------------- Tulajdonos

    public function test_a_tulajdonos_veglegesen_torolhet(): void
    {
        $this->belep(Szerep::Tulajdonos);
        $irat = $this->irat(DokumentumAllapot::Exportalva);

        Livewire::test(Archivum::class)->call('tetelTorles', $irat->id)->assertOk();

        $this->assertDatabaseMissing('documents', ['id' => $irat->id]);
    }
}
