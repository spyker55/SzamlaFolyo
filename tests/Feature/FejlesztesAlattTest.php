<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A figyelmeztetés a belépés előtti képernyőkön: az oldal él, de még nem kész.
 */
final class FejlesztesAlattTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bejelentkezesnel_figyelmeztet(): void
    {
        config(['szamlafolyo.fejlesztes_alatt' => true]);

        $this->get(route('bejelentkezes'))
            ->assertOk()
            ->assertSee('Az oldal fejlesztés alatt áll')
            ->assertSee('info@szamlafolyo.hu');
    }

    /**
     * A belépett felhasználót nem szabad minden oldalletöltésnél
     * megállítani — ő már tudja, hogy fejlesztés alatt áll.
     */
    public function test_a_belepett_felhasznalot_nem_zavarja(): void
    {
        config(['szamlafolyo.fejlesztes_alatt' => true]);

        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        $this->actingAs($user)
            ->get(route('beerkezo'))
            ->assertOk()
            ->assertDontSee('Az oldal fejlesztés alatt áll');
    }

    /** Indulás után egyetlen kapcsolóval eltüntethető. */
    public function test_kikapcsolva_nem_jelenik_meg(): void
    {
        config(['szamlafolyo.fejlesztes_alatt' => false]);

        $this->get(route('bejelentkezes'))
            ->assertOk()
            ->assertDontSee('Az oldal fejlesztés alatt áll');
    }
}
