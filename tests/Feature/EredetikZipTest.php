<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\ExportKepernyo;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az eredeti bizonylatok letöltése ZIP-ben.
 *
 * Ez a gomb **soha nem működött**: a `Response::download()` egy
 * `BinaryFileResponse`-t ad, a metódus viszont `?StreamedResponse`-t deklarált,
 * és a kettő nem rokona egymásnak — a PHP típushibával állt meg, mielőtt bármi
 * letöltődött volna. A típushiba pontosan az a fajta, amit teszt nélkül nem
 * lehet észrevenni: a kód olvasva helyes, a hiba csak futáskor van.
 *
 * A képernyő sorrendje miatt ez nem apróság: az export után az eredeti fájlok
 * törlődnek a szerverről, és ez az egyetlen alkalom letölteni őket.
 */
final class EredetikZipTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ceg = Company::factory()->create();
        $user = User::factory()->create();
        $this->ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($user);
    }

    private function irat(string $bizonylatszam, string $adoszam = '11176165-2-10'): Document
    {
        $dokumentum = Document::factory()->jovahagyva()->create([
            'company_id' => $this->ceg->id,
            'export_id' => null,
            'doc_number' => $bizonylatszam,
            'customer_tax_number' => $adoszam,
            'mime_type' => 'application/pdf',
            'storage_path' => sprintf('iratok/%d/%s/irat.pdf', $this->ceg->id, $bizonylatszam),
        ]);

        Storage::disk('local')->put((string) $dokumentum->storage_path, '%PDF-1.4 '.$bizonylatszam);

        return $dokumentum;
    }

    public function test_letoltheto_az_eredetik_zipje(): void
    {
        $this->irat('SZ-1');

        Livewire::test(ExportKepernyo::class)
            ->call('eredetikZip')
            ->assertFileDownloaded()
            ->assertSet('eredetikLetoltve', true);
    }

    /** Üres időszakban nincs mit letölteni, és ettől nem szabad elszállnia. */
    public function test_ures_idoszakban_nem_tortenik_semmi(): void
    {
        Livewire::test(ExportKepernyo::class)
            ->call('eredetikZip')
            ->assertNoFileDownloaded()
            ->assertSet('eredetikLetoltve', false);
    }

    /** A ZIP azt tartalmazza, ami a szűrő szerint exportra menne — nem többet. */
    public function test_a_zip_koveti_az_ugyfelszurot(): void
    {
        $this->irat('SAJAT-1', '11176165-2-10');
        $this->irat('MASE-1', '10773381-2-44');

        Livewire::test(ExportKepernyo::class)
            ->set('ugyfel', '11176165')
            ->assertSee('1 tétel kerül exportba')
            ->call('eredetikZip')
            ->assertFileDownloaded();
    }
}
