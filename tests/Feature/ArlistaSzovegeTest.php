<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Amit az árlista *mond* — nem a számok, hanem a mondatok körülöttük.
 *
 * A képernyő sokáig azt írta, hogy „a feltüntetett árak nettó árak". Ez nem
 * ártalmatlan félrefogalmazás volt: azt ígéri, hogy jön még rá áfa. A
 * Szolgáltató alanyi adómentes, a kiírt szám tehát a fizetendő végösszeg — és
 * az ÁSZF 7. pontja pontosan ezt mondja. A két állítás nem térhet el
 * egymástól: az egyik szerződés, a másik az, amit a vevő a gomb fölött lát.
 */
final class ArlistaSzovegeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: bool}> */
    public static function arlistak(): array
    {
        return [
            'nyitólap' => [false],
            'beállítások' => [true],
        ];
    }

    #[DataProvider('arlistak')]
    public function test_egyik_arlista_sem_iger_meg_egy_afat(bool $belepve): void
    {
        $this->arlista($belepve)->assertDontSee('nettó ár');
    }

    #[DataProvider('arlistak')]
    public function test_mindket_arlista_kimondja_a_vegosszeget(bool $belepve): void
    {
        $this->arlista($belepve)
            ->assertSee('a fizetendő végösszegek')
            ->assertSee('alanyi adómentes');
    }

    /**
     * A Pro csomagon a felhasználószám `null` — ez **korlátlant** jelent, nem
     * hiányzó beállítást. Kiírás nélkül a gombon „· fő" állt, szám nélkül.
     *
     * A configot a teszt maga állítja be, hogy a kiírás *módját* mérje, ne a
     * mai értéket: egy jogos csomagváltoztatás ne bukjon el rajta.
     */
    public function test_a_korlatlan_felhasznaloszam_ki_van_irva(): void
    {
        config(['szamlafolyo.plans.nagy.users' => null]);

        $this->arlista(true)->assertSee('korlátlan fő');
    }

    public function test_a_veges_felhasznaloszam_szammal_all(): void
    {
        config(['szamlafolyo.plans.nagy.users' => 7]);

        $this->arlista(true)->assertSee('7 fő');
    }

    private function arlista(bool $belepve): TestResponse
    {
        if (! $belepve) {
            return $this->get('/')->assertOk();
        }

        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        return $this->actingAs($user)->get('/beallitasok')->assertOk();
    }
}
