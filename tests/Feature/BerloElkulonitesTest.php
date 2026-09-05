<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\Beallitasok;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    /**
     * Éles hiba volt: a `ceg` middleware csak az oldal első betöltésekor fut
     * le, minden további Livewire-akció (feltöltés, mentés) egy saját
     * /livewire/update kérésen megy, amit Livewire alapból nem enged át a
     * saját middleware-jeinken — a Berlo ott üresen indult, és minden
     * ilyen kérés "Nincs kiválasztott cég." hibával, 500-zal elszállt.
     */
    public function test_egy_kulon_livewire_kerelmen_is_megvan_a_kivalasztott_ceg(): void
    {
        [$ceg, $user] = $this->cegFelhasznaloval();

        // A pillanatképet a valódi, /beerkezo-ra érkező HTML-ből szedjük ki:
        // csak ennek van olyan `path`/`method` memója, ami a `ceg` middleware-t
        // hordozó valódi útvonalra mutat. A Livewire::test() ehelyett egy
        // belső teszt-végpontot tesz a memóba, azon meg a middleware sosem
        // fut le — ez épp azt a hibát rejtette volna el, amit itt ellenőrzünk.
        $oldal = $this->actingAs($user)->get(route('beerkezo'));
        $oldal->assertOk();

        preg_match('/wire:snapshot="([^"]+)"/', $oldal->getContent(), $talalat);
        $this->assertArrayHasKey(1, $talalat, 'Nem található Livewire-pillanatkép a Beérkező oldalon.');
        $pillanatkep = html_entity_decode($talalat[1], ENT_QUOTES);

        // Az igazi /livewire/update kérés élesben egy önálló folyamatban fut:
        // a Berlo ott üresen indul, hacsak a middleware nem fut le rá újra.
        app(Berlo::class)->beallit(null);

        $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson('/livewire/update', ['components' => [
                ['snapshot' => $pillanatkep, 'updates' => [], 'calls' => []],
            ]])
            ->assertOk();
    }

    /** @return array{0: Company, 1: User} */
    /**
     * Egy tagfelvétel nem veheti el valaki más cégét.
     *
     * A hiba a két szabály együttállásából jött: a tulajdonos e-mail alapján,
     * elfogadás nélkül vehetett fel bárkit, a `User::ceg()` pedig a **legkisebb
     * cégazonosítót** adta vissza. Aki tehát később regisztrált, azt egy korábbi
     * cég tulajdonosa egyszerűen magához húzhatta: a következő belépéskor az
     * áldozat idegen cég Beérkezőjét látta a sajátja helyett — és oda töltötte
     * volna fel a saját számláit.
     *
     * Két oldalról zárjuk. Itt az első: ilyen sor létre sem jön.
     */
    public function test_mashol_dolgozo_fiokot_nem_lehet_atvenni(): void
    {
        [$tamado, $tulajdonos] = $this->cegFelhasznaloval();
        [, $aldozat] = $this->cegFelhasznaloval();

        app(Berlo::class)->beallit($tamado);
        $this->actingAs($tulajdonos);

        Livewire::test(Beallitasok::class)
            ->set('ujTagEmail', $aldozat->email)
            ->set('ujTagSzerep', Szerep::Megtekinto->value)
            ->call('tagFelvetel')
            ->assertHasErrors('ujTagEmail');

        $this->assertFalse($tamado->users()->where('users.id', $aldozat->id)->exists());
    }

    /**
     * A második zár, a meglévő adatokra: ha egy ilyen sor mégis létezik, a
     * felhasználó akkor is ott marad, ahol dolgozni kezdett. A cége a
     * **legkorábbi tagsága**, nem a legkisebb sorszámú cég — itt szándékosan
     * az utóbb létrejött tagság mutat a kisebb azonosítójú cégre.
     */
    public function test_a_felhasznalo_cege_a_legkorabbi_tagsaga(): void
    {
        $regiCeg = Company::factory()->create();     // kisebb azonosító
        $sajatCeg = Company::factory()->create();    // nagyobb azonosító
        $user = User::factory()->create();

        $sajatCeg->users()->attach($user->id, [
            'role' => Szerep::Tulajdonos->value,
            'accepted_at' => now(),
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $regiCeg->users()->attach($user->id, [
            'role' => Szerep::Megtekinto->value,
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($regiCeg->id < $sajatCeg->id);
        $this->assertSame($sajatCeg->id, $user->fresh()->ceg()?->id);
    }

    private function cegFelhasznaloval(): array
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        return [$ceg, $user];
    }
}
