<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A legfontosabb teszt az egész alkalmazásban. Ha ez elromlik, egy cég látja
 * a másik bizonylatait — és ennél rosszabb hiba nincs egy könyvelési
 * rendszerben.
 */
final class BerloElkulonitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_egy_ceg_nem_latja_a_masik_dokumentumait(): void
    {
        [$cegA, $userA] = $this->cegFelhasznaloval();
        [$cegB] = $this->cegFelhasznaloval();

        Document::factory()->count(3)->jovahagyva()->create(['company_id' => $cegA->id]);
        Document::factory()->count(2)->jovahagyva()->create(['company_id' => $cegB->id]);

        app(Berlo::class)->beallit($cegA);

        $this->assertSame(3, Document::query()->count());
        $this->assertTrue(Document::query()->get()->every(fn (Document $d): bool => $d->company_id === $cegA->id));

        // Az összes sor attól még ott van — csak nem látszik.
        $this->assertSame(5, Document::query()->withoutGlobalScopes()->count());
    }

    public function test_masik_ceg_irata_nem_erheto_el_url_rol(): void
    {
        [$cegA, $userA] = $this->cegFelhasznaloval();
        [$cegB] = $this->cegFelhasznaloval();

        $idegen = Document::factory()->ellenorzesreVar()->create(['company_id' => $cegB->id]);

        $this->actingAs($userA)
            ->get(route('ellenorzes', $idegen))
            ->assertNotFound();

        $this->actingAs($userA)
            ->get(route('dokumentum.fajl', $idegen))
            ->assertNotFound();
    }

    public function test_a_company_id_akkor_is_kitoltodik_ha_a_hivo_elfelejti(): void
    {
        [$ceg] = $this->cegFelhasznaloval();
        app(Berlo::class)->beallit($ceg);

        $dokumentum = Document::create([
            'original_filename' => 'proba.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->assertSame($ceg->id, $dokumentum->company_id);
    }

    /** @return array{0: Company, 1: User} */
    private function cegFelhasznaloval(): array
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        return [$ceg, $user];
    }
}
