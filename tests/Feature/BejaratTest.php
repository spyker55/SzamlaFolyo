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
 * A bejárati ajtó: mit lát az, aki még nem lépett be.
 *
 * Ez a teszt egy éles hiba után született. A Laravel a bejelentkezés nélküli
 * látogatót a `login` **nevű** útvonalra küldi; a mi bejelentkezésünk viszont
 * `bejelentkezes`, így a keret olyan útvonalat keresett, ami nincs, és a
 * főoldal **500-as hibát adott mindenkinek, aki még nem regisztrált**.
 *
 * A korábbi tesztek ezt nem foghatták meg: mindegyik `actingAs`-szal indult,
 * a böngészős próba pedig regisztrációval kezdett. A be nem lépett látogató
 * útját senki nem járta végig — pedig minden új felhasználó ott kezdi.
 */
final class BejaratTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function vedettUtvonalak(): array
    {
        return [
            'beérkező' => ['/beerkezo'],
            'tételek' => ['/tetelek'],
            'export' => ['/export'],
            'archívum' => ['/archivum'],
            'beállítások' => ['/beallitasok'],
            'cégnyitás' => ['/ceg-letrehozas'],
        ];
    }

    #[DataProvider('vedettUtvonalak')]
    public function test_vendeget_a_bejelentkezesre_kuldi_nem_hibara(string $utvonal): void
    {
        $this->get($utvonal)->assertRedirect(route('bejelentkezes'));
    }

    public function test_a_fooldal_vegul_a_bejelentkezesre_visz(): void
    {
        $this->followingRedirects()
            ->get('/')
            ->assertOk()
            ->assertSee('Bejelentkezés');
    }

    public function test_a_bejelentkezo_oldal_vendegkent_megnyilik(): void
    {
        $this->get(route('bejelentkezes'))->assertOk()->assertSee('Bejelentkezés');
        $this->get(route('regisztracio'))->assertOk()->assertSee('Regisztráció');
    }

    /** Aki már belépett, annak a bejelentkező oldalon nincs dolga. */
    public function test_belepett_felhasznalot_elvezeti_a_bejelentkezesrol(): void
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        $this->actingAs($user)
            ->get(route('bejelentkezes'))
            ->assertRedirect(route('beerkezo'));
    }

    /** Cég nélkül csak a cégnyitó képernyő érhető el — de az igen. */
    public function test_ceg_nelkuli_felhasznalot_a_cegnyitora_kuldi(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/beerkezo')->assertRedirect(route('ceg.letrehozas'));
        $this->actingAs($user)->get(route('ceg.letrehozas'))->assertOk();
    }
}
