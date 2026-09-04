<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cégtörlés parancssorból.
 *
 * Egy elgépelt név itt egy teljes ügyfélarchívumot vinne el némán, ezért a
 * tesztek java arról szól, mikor **nem** töröl.
 */
final class CegTorolTest extends TestCase
{
    use RefreshDatabase;

    public function test_torli_a_ceget(): void
    {
        Storage::fake('local');
        $ceg = Company::factory()->create(['name' => 'Centervill Kft.']);
        Storage::disk('local')->put('iratok/'.$ceg->id.'/1/a.pdf', 'x');

        $this->artisan('ceg:torol', ['nev' => 'Centervill Kft.', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('companies', ['id' => $ceg->id]);
        $this->assertFalse(Storage::disk('local')->exists('iratok/'.$ceg->id.'/1/a.pdf'));
    }

    public function test_nem_letezo_nevre_nem_csinal_semmit(): void
    {
        Company::factory()->create(['name' => 'Megmarad Kft.']);

        $this->artisan('ceg:torol', ['nev' => 'Nincs Ilyen Kft.', '--force' => true])
            ->assertFailed();

        $this->assertDatabaseCount('companies', 1);
    }

    /** Azonos néven futó cégek közül nem találgatunk. */
    public function test_tobb_azonos_nevu_ceg_eseten_megall(): void
    {
        Company::factory()->count(2)->create(['name' => 'Ikrek Kft.']);

        $this->artisan('ceg:torol', ['nev' => 'Ikrek Kft.', '--force' => true])
            ->assertFailed();

        $this->assertDatabaseCount('companies', 2);
    }

    /** Élő előfizetés mellett a törlés után is számláznánk. */
    public function test_elo_elofizetessel_nem_torol(): void
    {
        $ceg = Company::factory()->create(['name' => 'Fizető Kft.', 'stripe_subscription_id' => 'sub_123']);

        $this->artisan('ceg:torol', ['nev' => 'Fizető Kft.', '--force' => true])
            ->assertFailed();

        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
    }

    /** Kérdés nélkül csak `--force`-szal töröl. */
    public function test_megerosites_nelkul_nem_torol(): void
    {
        $ceg = Company::factory()->create(['name' => 'Kérdéses Kft.']);
        Document::factory()->create(['company_id' => $ceg->id]);

        $this->artisan('ceg:torol', ['nev' => 'Kérdéses Kft.'])
            ->expectsConfirmation('Ezzel 1 bizonylat és minden hozzá tartozó adat VÉGLEG elvész. Biztos?', 'no')
            ->assertSuccessful();

        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
    }
}
