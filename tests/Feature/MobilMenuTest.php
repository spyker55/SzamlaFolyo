<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumAllapot;
use App\Enums\Szerep;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A mobil menü.
 *
 * Régen a fejléc alatt egy vízszintes csúszka állt: az utolsó két menüpont
 * csak oldalra húzva látszott, és a sáv minden képernyőn elvitt egy sort.
 * Helyette hamburger nyit egy fiókot.
 *
 * A fiók viselkedését az Alpine adja, azt innen nem látjuk — azt kézzel
 * próbáltuk ki. Amit itt őrzünk, az a kiszolgált oldal: a gomb ahhoz a
 * fiókhoz szól, ami tényleg ott van; a fiók zárva indul; és a jelzőszám
 * ugyanaz a szám mindkét helyen.
 */
final class MobilMenuTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceg = Company::factory()->create();
        $this->user = User::factory()->create();
        $this->ceg->users()->attach($this->user->id, [
            'role' => Szerep::Tulajdonos->value,
            'accepted_at' => now(),
        ]);
        app(Berlo::class)->beallit($this->ceg);
    }

    public function test_a_hamburger_ahhoz_a_fiokhoz_szol_ami_ott_van(): void
    {
        $html = $this->actingAs($this->user)->get('/beerkezo')->getContent();

        $this->assertStringContainsString('aria-controls="mobil-menu"', $html);
        $this->assertStringContainsString('id="mobil-menu"', $html);
    }

    /**
     * A fiók zárva indul, és zárva is látszik az első képkockán. Az `x-cloak`
     * nélkül minden oldalbetöltéskor felvillanna kinyitva.
     */
    public function test_a_fiok_zarva_indul(): void
    {
        $html = $this->actingAs($this->user)->get('/beerkezo')->getContent();

        $this->assertMatchesRegularExpression(
            '/id="mobil-menu"[^>]*x-cloak/s',
            $html,
            'A fiók x-cloak nélkül van — betöltéskor felvillan.'
        );
        $this->assertStringContainsString('x-data="{ menu: false }"', $html);
    }

    /**
     * A menüpontok pontosan kétszer szerepelnek: az oldalsávban és a fiókban.
     * Ha valaki visszatesz egy harmadik, mindig látszó menüsort, ez elbukik.
     */
    public function test_a_menupontok_csak_az_oldalsavban_es_a_fiokban_vannak(): void
    {
        $html = $this->actingAs($this->user)->get('/beerkezo')->getContent();

        foreach (['/beerkezo', '/tetelek', '/export', '/archivum', '/beallitasok'] as $utvonal) {
            $this->assertSame(
                2,
                substr_count($html, 'href="'.url($utvonal).'"'),
                "A(z) {$utvonal} menüpont nem pontosan kétszer szerepel."
            );
        }
    }

    public function test_a_varakozo_iratokat_a_gomb_es_a_menupont_is_jelzi(): void
    {
        Document::factory()->count(2)->create([
            'company_id' => $this->ceg->id,
            'status' => DokumentumAllapot::EllenorzesreVar->value,
        ]);
        Document::factory()->create([
            'company_id' => $this->ceg->id,
            'status' => DokumentumAllapot::Hiba->value,
        ]);

        // Ezekről nincs mit kérdezni az embertől, tehát nem is számítanak.
        Document::factory()->create([
            'company_id' => $this->ceg->id,
            'status' => DokumentumAllapot::FeldolgozasAlatt->value,
        ]);
        Document::factory()->create([
            'company_id' => $this->ceg->id,
            'status' => DokumentumAllapot::Jovahagyva->value,
        ]);

        $html = $this->actingAs($this->user)->get('/beerkezo')->getContent();

        $this->assertStringContainsString('Menü megnyitása – 3 irat vár', $html);
        $this->assertSame(
            2,
            preg_match_all('/bg-amber-500 px-1\.5[^>]*>\s*3\s*</', $html),
            'A hármas nem szerepel mindkét menü „Beérkező" pontján.'
        );
    }

    public function test_nyugalmi_allapotban_nincs_jelzes(): void
    {
        Document::factory()->create([
            'company_id' => $this->ceg->id,
            'status' => DokumentumAllapot::Jovahagyva->value,
        ]);

        $html = $this->actingAs($this->user)->get('/beerkezo')->getContent();

        $this->assertStringContainsString('aria-label="Menü megnyitása"', $html);
        $this->assertStringNotContainsString('irat vár', $html);
        $this->assertStringNotContainsString('bg-amber-500', $html);
    }
}
