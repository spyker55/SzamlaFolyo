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
     * Amíg a szöveg nincs meg, ezt ki is mondjuk. Egy odavetett „mintaszöveg"
     * rosszabb lenne a hiányánál: a látogató elhinné.
     */
    #[DataProvider('oldalak')]
    public function test_addig_megmondja_hogy_keszul(string $utvonal): void
    {
        $this->get($utvonal)
            ->assertOk()
            ->assertSee('jelenleg készül')
            ->assertSee(config('szamlafolyo.kapcsolat_email'));
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
