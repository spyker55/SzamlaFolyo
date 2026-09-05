<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A három jogi oldal.
 *
 * Két dolgot őrzünk. Az egyik, hogy **hitelesítés nélkül is** olvashatók: az
 * ÁSZF-et a regisztráció előtt kell tudni elolvasni, különben fiók kellene
 * ahhoz, amihez a fiók feltétele kötődik — és a védett útvonalak listája
 * amúgy is a bejelentkezésre irányít mindent, amit nem vettünk ki alóla.
 *
 * A másik, hogy a linkjük **belépve is ott van**: az alkalmazásnak eddig nem
 * volt lábléce, tehát ez az a fajta hely, ahonnan egy átrendezés csendben
 * kiejtené őket.
 */
final class JogiOldalakTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function oldalak(): array
    {
        return [
            'ÁSZF' => ['/aszf', 'Általános Szerződési Feltételek'],
            'adatkezelés' => ['/adatkezeles', 'Adatkezelési tájékoztató'],
            'impresszum' => ['/impresszum', 'Impresszum'],
        ];
    }

    #[DataProvider('oldalak')]
    public function test_vendegkent_is_olvashato(string $utvonal, string $cim): void
    {
        $this->get($utvonal)->assertOk()->assertSee($cim);
    }

    #[DataProvider('oldalak')]
    public function test_belepve_is_ugyanaz_jon(string $utvonal, string $cim): void
    {
        $this->actingAs($this->belepett())->get($utvonal)->assertOk()->assertSee($cim);
    }

    /**
     * Mind a három szöveg megvan. Ez az állítás azt őrzi, hogy egyik oldal se
     * essen vissza a „készül" állapotba egy elrontott átszerkesztéstől.
     */
    #[DataProvider('oldalak')]
    public function test_egyik_oldal_sem_placeholder(string $utvonal): void
    {
        $this->get($utvonal)->assertOk()->assertDontSee('jelenleg készül');
    }

    /**
     * Az adatkezelési tájékoztató megőrzési idői **abból a configból** jönnek,
     * amiből a `fajl:selejtez` is dolgozik. Ha kézzel volnának beírva, a
     * takarítás és az ígéret külön életet kezdene élni — és a tájékoztató az,
     * ami hazudni kezdene, nem a program.
     *
     * A `config()` és nem `env()` külön is számít: egy nézetben hívott `env()`
     * `config:cache` után `null`, vagyis élesben nulla napot ígérnénk.
     */
    public function test_az_adatkezelesi_a_configbol_veszi_a_megorzest(): void
    {
        config([
            'inbox.imap.keep_days' => 9,
            'inbox.imap.unmatched_keep_days' => 21,
            'openrouter.model' => 'peldagyarto/proba-modell',
        ]);

        $this->get('/adatkezeles')
            ->assertOk()
            ->assertSee('9 napig')
            ->assertSee('21 napig')
            ->assertSee('peldagyarto/proba-modell');
    }

    /**
     * Az adatáramlás azon pontjai, amelyeket ki kell mondani: a két szerepkör,
     * a szerverelhagyás a kiolvasásnál, hogy az e-számla nem hagyja el, és
     * hogy a Google Fonts miatt a látogató IP-címe a Google-höz kerül.
     */
    public function test_az_adatkezelesi_kimondja_az_adataramlast(): void
    {
        $valasz = $this->get('/adatkezeles')->assertOk();

        foreach ([
            'A fiók adataira nézve a Szolgáltató az adatkezelő',
            'A feltöltött bizonylatokra nézve az Előfizető az adatkezelő',
            'elhagyja a szervert',
            'modellhívás nélkül',
            'IP-címe és böngészőjének adatai eljutnak a Google-höz',
            'Nemzeti Adatvédelmi és Információszabadság Hatóság',
        ] as $allitas) {
            $valasz->assertSee($allitas);
        }
    }

    /**
     * Az ÁSZF **számai a configból jönnek**, nem a szövegbe írva. Ezért a
     * teszt sem azt nézi, hogy „1990" ott van-e, hanem hogy egy megváltozott
     * ár átüt-e a lapon: egy kézzel bemásolt szám különben csendben elcsúszna
     * attól, amit a rendszer valóban ad — és egy szerződésben ez nem
     * elírás, hanem az ígéret és a teljesítés szétválása.
     */
    public function test_az_aszf_a_configbol_veszi_a_szamokat(): void
    {
        config([
            'szamlafolyo.plans.kicsi.ar_havi' => 2490,
            'szamlafolyo.plans.kicsi.documents' => 70,
            'szamlafolyo.trial.days' => 21,
        ]);

        $this->get('/aszf')
            ->assertOk()
            ->assertDontSee('jelenleg készül')
            ->assertSee('2 490 Ft')
            ->assertSee('21 napos');
    }

    /**
     * A vállalás lényege: a kiolvasás tervezet, a jóváhagyás az emberé, és a
     * bizonylatok jogszabályi megőrzése az ügyfélé marad attól, hogy mi
     * törlünk. Ha ezek kikopnak a szövegből, az nem stiláris kérdés.
     */
    public function test_az_aszf_kimondja_a_lenyeget(): void
    {
        $valasz = $this->get('/aszf')->assertOk();

        foreach ([
            'A gépi kiolvasás eredménye tervezet',
            'kizárólag a Polgári Törvénykönyv szerinti vállalkozások',
            'nem minősül könyvelési, adótanácsadási vagy jogi szolgáltatásnak',
            'A bizonylatok jogszabályi megőrzése az Előfizető kötelezettsége',
            'adatfeldolgozóként jár el',
            'alanyi adómentes',
        ] as $vallalas) {
            $valasz->assertSee($vallalas);
        }
    }

    /**
     * Az impresszum kész, és a benne közölt adatok azonosítanak: ha bármelyik
     * kiesik a lapról, az nem szépséghiba, hanem hiányos közzététel
     * (Ekertv. 4. §). Ezért soronként ellenőrizzük, nem csak azt, hogy az
     * oldal betölt.
     */
    public function test_az_impresszum_kesz(): void
    {
        $valasz = $this->get('/impresszum')->assertOk()->assertDontSee('jelenleg készül');

        foreach ([
            'Nyeste Krisztián egyéni vállalkozó',
            '3000 Hatvan, István király utca 7.',
            'Nemzeti Adó- és Vámhivatal',
            '62574956',
            '92220155-1-30',
            '+36 70 604 3043',
            'Nethely Kft.',
            '1115 Budapest, Halmi utca 29.',
            '01-09-961790',
        ] as $adat) {
            $valasz->assertSee($adat);
        }

        $valasz->assertSee(config('szamlafolyo.kapcsolat_email'));
    }

    /**
     * Az EU online vitarendezési platformja az (EU) 2024/3228 rendelet nyomán
     * 2025. július 20-án megszűnt. A magyar impresszum-sablonok javában máig
     * ott a linkje — ez az állítás azt őrzi, hogy egy későbbi másolással se
     * kerüljön vissza egy halott hatósági útra mutató hivatkozás.
     */
    public function test_nincs_benne_a_megszunt_odr_platform(): void
    {
        $this->get('/impresszum')
            ->assertOk()
            ->assertDontSee('ec.europa.eu/consumers/odr')
            ->assertDontSee('vitarendezési platform');
    }

    /**
     * Vezet út vissza a főoldalra. A fejlécben ott a logó, de arról egy
     * kattintható logó soha nem mondja meg magáról, hogy az — és ide sokan a
     * kereső felől érkeznek, nem az oldalról.
     */
    #[DataProvider('oldalak')]
    public function test_van_ut_vissza_a_fooldalra(string $utvonal): void
    {
        $this->get($utvonal)
            ->assertOk()
            ->assertSee('Vissza a főoldalra')
            ->assertSee('href="'.route('kezdolap').'"', false);
    }

    public function test_a_nyitolap_lablecebol_elerhetok(): void
    {
        $valasz = $this->get('/')->assertOk();

        foreach (['ÁSZF', 'Adatkezelés', 'Impresszum'] as $cimke) {
            $valasz->assertSee($cimke);
        }
        foreach (['aszf', 'adatkezeles', 'impresszum'] as $utvonal) {
            $valasz->assertSee('href="'.route($utvonal).'"', false);
        }
    }

    public function test_belepve_a_lablecbol_elerhetok(): void
    {
        $valasz = $this->actingAs($this->belepett())->get('/beerkezo')->assertOk();

        foreach (['aszf', 'adatkezeles', 'impresszum'] as $utvonal) {
            $valasz->assertSee('href="'.route($utvonal).'"', false);
        }
    }

    private function belepett(): User
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        return $user;
    }
}
