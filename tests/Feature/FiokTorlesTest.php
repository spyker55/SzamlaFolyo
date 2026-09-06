<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\FiokTorles;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Account\ElofizetesKapu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A saját fiók törlése.
 *
 * Ez a rendszer egyetlen olyan művelete, ami visszafordíthatatlan és nincs
 * mögötte mentés — a tesztek java ezért arról szól, mikor **nem** töröl, és
 * mi az, aminek a törlés után is el kell tűnnie.
 *
 * A vezérlő szabály: a fiók a felhasználóé, a cég pedig akkor szűnik meg, ha
 * az utolsó ember is elment.
 */
final class FiokTorlesTest extends TestCase
{
    use RefreshDatabase;

    /** A Stripe helyett egy naplózó kapu; élesben ez mondaná le az előfizetést. */
    private function szamlazo(bool $beallitva = true, bool $hibazik = false): object
    {
        $kapu = new class($beallitva, $hibazik) implements ElofizetesKapu
        {
            /** @var list<string> */
            public array $lemondva = [];

            public function __construct(private bool $beallitva, private bool $hibazik) {}

            public function beallitva(): bool
            {
                return $this->beallitva;
            }

            public function elofizetestLemond(string $id): void
            {
                if ($this->hibazik) {
                    throw new RuntimeException('A Stripe most nem elérhető.');
                }

                $this->lemondva[] = $id;
            }
        };

        $this->instance(ElofizetesKapu::class, $kapu);

        return $kapu;
    }

    private function tag(Company $ceg, Szerep $szerep, string $jelszo = 'jelszo123'): User
    {
        $user = User::factory()->create(['password' => $jelszo]);
        $ceg->users()->attach($user->id, ['role' => $szerep->value, 'accepted_at' => now()]);

        return $user;
    }

    // ---------------------------------------------------------------- töröl

    public function test_az_utolso_felhasznalo_torlese_a_ceget_is_elviszi(): void
    {
        Storage::fake('local');
        $kapu = $this->szamlazo();

        $ceg = Company::factory()->create(['stripe_subscription_id' => 'sub_123']);
        $user = $this->tag($ceg, Szerep::Tulajdonos);
        $irat = Document::factory()->create(['company_id' => $ceg->id]);
        Storage::disk('local')->put("iratok/{$ceg->id}/1/szamla.pdf", 'x');
        Storage::disk('local')->put("exportok/{$ceg->id}/export.xlsx", 'y');

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors()
            ->assertRedirect(route('bejelentkezes'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('companies', ['id' => $ceg->id]);
        $this->assertDatabaseMissing('documents', ['id' => $irat->id]);

        // A fájlok nem esnek a `cascadeOnDelete` hatálya alá: ezek idegen
        // cégek számlái, róluk másolat nem maradhat egy osztott tárhelyen.
        $this->assertFalse(Storage::disk('local')->exists("iratok/{$ceg->id}/1/szamla.pdf"));
        $this->assertFalse(Storage::disk('local')->exists("exportok/{$ceg->id}/export.xlsx"));

        $this->assertSame(['sub_123'], $kapu->lemondva);
        $this->assertStringContainsString('előfizetés lemondva', (string) session('siker'));
    }

    /**
     * Előfizetés nélkül nem beszélünk lemondásról. Egy meg nem történt
     * lépésről beszámolni ugyanolyan hiba, mint elhallgatni egy megtörténtet.
     */
    public function test_elofizetes_nelkul_nem_emlegetunk_lemondast(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create(['stripe_subscription_id' => null]);
        $user = $this->tag($ceg, Szerep::Tulajdonos);

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors();

        $this->assertStringNotContainsString('lemond', (string) session('siker'));
    }

    /** Aki nem az utolsó, az csak magát viszi. */
    public function test_tag_torlese_a_ceget_nem_bantja(): void
    {
        $kapu = $this->szamlazo();

        $ceg = Company::factory()->create(['stripe_subscription_id' => 'sub_123']);
        $tulaj = $this->tag($ceg, Szerep::Tulajdonos);
        $tag = $this->tag($ceg, Szerep::Szerkeszto);
        $irat = Document::factory()->create(['company_id' => $ceg->id]);

        Livewire::actingAs($tag)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $tag->id]);
        $this->assertDatabaseHas('users', ['id' => $tulaj->id]);
        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
        $this->assertDatabaseHas('documents', ['id' => $irat->id]);

        // A cég fizet tovább: nem a mi dolgunk lemondani más nevében.
        $this->assertSame([], $kapu->lemondva);

        // A bentmaradóknak látszania kell, hogy valaki kilépett.
        $this->assertDatabaseHas('activity_log', [
            'company_id' => $ceg->id,
            'action' => 'fiok.torolve',
            'summary' => $tag->email,
        ]);
    }

    /** Két tulajdonos közül az egyik elmehet: a cégnek marad gazdája. */
    public function test_ket_tulajdonos_kozul_az_egyik_torolhet(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create();
        $egyik = $this->tag($ceg, Szerep::Tulajdonos);
        $masik = $this->tag($ceg, Szerep::Tulajdonos);

        Livewire::actingAs($egyik)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $egyik->id]);
        $this->assertDatabaseHas('users', ['id' => $masik->id]);
        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
    }

    public function test_ceg_nelkuli_felhasznalo_is_torolheti_magat(): void
    {
        $this->szamlazo();

        $user = User::factory()->create(['password' => 'jelszo123']);

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->assertSee('nem tartozik cég')
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    // -------------------------------------------------------------- nem töröl

    /**
     * Az egyetlen tulajdonos nem hagyhat maga után gazdátlan céget: nem lenne,
     * aki a tagokat kezelje vagy az előfizetést lemondja.
     */
    public function test_utolso_tulajdonos_nem_torolhet_ha_masok_maradnak(): void
    {
        $kapu = $this->szamlazo();

        $ceg = Company::factory()->create(['stripe_subscription_id' => 'sub_123']);
        $tulaj = $this->tag($ceg, Szerep::Tulajdonos);
        $this->tag($ceg, Szerep::Szerkeszto);

        Livewire::actingAs($tulaj)->test(FiokTorles::class)
            ->assertSee('egyetlen tulajdonosa')
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasErrors('jelszo');

        $this->assertDatabaseHas('users', ['id' => $tulaj->id]);
        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
        $this->assertSame([], $kapu->lemondva);
    }

    public function test_rossz_jelszoval_nem_torol(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create();
        $user = $this->tag($ceg, Szerep::Tulajdonos);

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->set('jelszo', 'nem-ez-az')
            ->call('torles')
            ->assertHasErrors('jelszo');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
    }

    /**
     * A legfontosabb eset. Ha a lemondás nem megy át, inkább ne töröljünk:
     * a törölt fiók mögött futva maradt előfizetést a felhasználó már sehol
     * nem tudná lemondani, és hónapokig fizetne a semmiért.
     */
    public function test_a_szamlazo_hibaja_megallitja_a_torlest(): void
    {
        Storage::fake('local');
        $this->szamlazo(hibazik: true);

        $ceg = Company::factory()->create(['stripe_subscription_id' => 'sub_123']);
        $user = $this->tag($ceg, Szerep::Tulajdonos);
        $irat = Document::factory()->create(['company_id' => $ceg->id]);
        Storage::disk('local')->put("iratok/{$ceg->id}/1/szamla.pdf", 'x');

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertRedirect(route('bejelentkezes'));

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('companies', ['id' => $ceg->id]);
        $this->assertDatabaseHas('documents', ['id' => $irat->id]);
        $this->assertTrue(Storage::disk('local')->exists("iratok/{$ceg->id}/1/szamla.pdf"));

        // A munkamenet ilyenkor már nincs meg, ezért az indoklás a
        // bejelentkező képernyőre kerül — különben a felhasználó némán
        // találná magát kint, és azt hinné, törölve lett.
        $this->assertStringContainsString('Stripe', (string) session('hiba'));
    }

    public function test_tul_sok_jelszoprobalkozas_utan_megall(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create();
        $user = $this->tag($ceg, Szerep::Tulajdonos);
        RateLimiter::clear('fiok-torles:'.$user->id);

        $kepernyo = Livewire::actingAs($user)->test(FiokTorles::class);

        for ($i = 0; $i < 5; $i++) {
            $kepernyo->set('jelszo', 'rossz')->call('torles')->assertHasErrors('jelszo');
        }

        // A hatodik már a helyes jelszót sem nézi meg.
        $kepernyo->set('jelszo', 'jelszo123')->call('torles');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * A `logout()` friss „emlékezz rám" tokent ír a felhasználó sorába, és egy
     * már törölt modellen ez INSERT-té válik: a fiók a saját azonosítójával
     * visszakerül. A hiba először pont így jelentkezett — a cég eltűnt, a
     * felhasználó megmaradt —, és a felületen semmi nem árulta volna el.
     */
    public function test_a_torolt_fiok_nem_tamad_fel(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create();
        $user = $this->tag($ceg, Szerep::Tulajdonos);
        $azonosito = $user->id;
        $email = $user->email;

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors();

        // Sem azonosító, sem e-mail cím alapján: az `id` egy visszaírt
        // sorban is stimmelne, az e-mail viszont akkor is elárulná.
        $this->assertDatabaseMissing('users', ['id' => $azonosito]);
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertSame(0, User::query()->count());
    }

    // ------------------------------------------------------------ maradványok

    /**
     * A `password_reset_tokens` e-mail címre szól, nem azonosítóra. Ha bent
     * marad, és később valaki ugyanezzel a címmel regisztrál, egy régi
     * tokennel át lehetne venni az új fiókot.
     */
    public function test_a_jelszo_token_es_a_munkamenet_is_elmegy(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create();
        $user = $this->tag($ceg, Szerep::Tulajdonos);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'regi-token',
            'created_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'munkamenet-1',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->set('jelszo', 'jelszo123')
            ->call('torles')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'munkamenet-1']);
    }

    // ------------------------------------------------------------- a képernyő

    /** Amit a képernyő ígér, annak a tervből kell jönnie, nem a nézetből. */
    public function test_a_kepernyo_elore_megmondja_mi_fog_tortenni(): void
    {
        $this->szamlazo();

        $ceg = Company::factory()->create([
            'name' => 'Egyedülálló Kft.',
            'stripe_subscription_id' => 'sub_123',
        ]);
        $user = $this->tag($ceg, Szerep::Tulajdonos);
        Document::factory()->count(3)->create(['company_id' => $ceg->id]);

        Livewire::actingAs($user)->test(FiokTorles::class)
            ->assertSee('Egyedülálló Kft.')
            ->assertSee('a cég is megszűnik')
            ->assertSee('Az előfizetést azonnal lemondjuk.')
            ->assertSee('nem téríthető vissza')
            ->assertSee('3');
    }
}
