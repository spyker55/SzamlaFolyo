<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A nyilvános oldal.
 *
 * A lényegi állítás nem az, hogy „szerepel rajta a 14 nap" — hanem hogy a
 * **konfigurációból** szerepel. A próbaidő hossza és darabszáma üzleti döntés,
 * és ha egyszer kézzel beírt számként kerül a marketingbe, akkor a következő
 * változtatás után az oldal olyat ígér, amit a rendszer nem ad meg.
 */
final class NyitolapTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_probaido_harom_tenye_kiirva(): void
    {
        config(['szamlafolyo.trial.days' => 14, 'szamlafolyo.trial.documents' => 20]);

        $this->get('/')
            ->assertOk()
            ->assertSee('14 nap próbaidő')
            ->assertSee('20 dokumentum ingyen')
            ->assertSee('Nem kér bankkártyát');
    }

    /** Ha a keret változik, a nyitólap magától követi. */
    public function test_a_probaido_a_konfiguraciobol_jon(): void
    {
        config(['szamlafolyo.trial.days' => 30, 'szamlafolyo.trial.documents' => 5]);

        $this->get('/')
            ->assertOk()
            ->assertSee('30 nap próbaidő')
            ->assertSee('5 dokumentum ingyen')
            ->assertDontSee('14 nap próbaidő');
    }

    /** A csomagok darabszáma is a konfigurációé, nem a szövegé. */
    public function test_a_csomagok_darabszama_a_konfiguraciobol_jon(): void
    {
        config(['szamlafolyo.plans.kozepes.documents' => 250]);

        $this->get('/')
            ->assertOk()
            ->assertSee('250 dokumentum', escape: false)
            ->assertSee('500 dokumentum', escape: false);
    }

    /**
     * Az árlista minden száma a konfigurációból jön: ár, darabszám,
     * felhasználószám, darabár. Az árlistán álló szám szerződéses ígéret —
     * kézzel beírva előbb-utóbb elcsúszik attól, amit a rendszer valóban ad.
     */
    public function test_az_arlista_a_konfiguraciobol_jon(): void
    {
        config([
            'szamlafolyo.plans.kozepes.ar_havi' => 5990,
            'szamlafolyo.plans.kozepes.users' => 4,
            'szamlafolyo.plans.kozepes.extra_ft' => 33,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('5 990')
            ->assertSee('4 felhasználó', escape: false)
            ->assertSee('Extra dokumentum: 33 Ft');
    }

    /**
     * Éves fizetés nincs, és az árlista sem kínálhatja.
     *
     * A keret a Stripe számlázási ciklusára szól, egy éves előfizetés tehát
     * évi keretet adna a havi helyett. Amíg ez így van, az oldalon nem
     * jelenhet meg éves ár — az ígéret volna, amit a rendszer nem tart be.
     */
    public function test_az_arlista_nem_kinal_eves_fizetest(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Ft / hó')
            ->assertDontSee('Éves fizetés')
            ->assertDontSee('12 hónap 10 havi díjért');
    }

    /** A kereten felüli feldolgozás felső határa is előre kimondott ígéret. */
    public function test_az_arlista_kimondja_a_tulhasznalat_hatarat(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('az általad megadott forintos határig', escape: false);
    }

    /** A két szabály, amit a doksi szerint előre ki kell mondani. */
    public function test_a_ket_szabaly_ki_van_mondva(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('alapból megáll')
            ->assertSee('Az első 5 oldal egy dokumentum');
    }

    public function test_a_gombok_a_regisztraciora_visznek(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('regisztracio'))
            ->assertSee(route('bejelentkezes'));
    }

    /** Aki már belépett, annak a marketinggel nincs dolga. */
    public function test_belepett_felhasznalot_a_beerkezore_kuldi(): void
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        $this->actingAs($user)->get('/')->assertRedirect(route('beerkezo'));
    }

    /**
     * A fejlesztés alatti figyelmeztetés a nyitólapon is ott van. Ez most az
     * első képernyő, amit egy látogató lát — épp itt a legfontosabb kimondani,
     * hogy a szolgáltatás még nem teljes.
     */
    public function test_a_fejlesztes_alatti_figyelmeztetes_ide_is_kikerul(): void
    {
        config(['szamlafolyo.fejlesztes_alatt' => true]);

        $this->get('/')->assertOk()->assertSee('Az oldal fejlesztés alatt áll');
    }
}
