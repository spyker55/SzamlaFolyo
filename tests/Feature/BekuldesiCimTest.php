<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\Beallitasok;
use App\Models\Company;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A beküldési cím megjelenése.
 *
 * A tokent a `Company` mindig legenerálta, a beérkeztetés a háttérben kész volt
 * — a cím viszont **sehol nem jelent meg a felületen**, tehát nem lehetett
 * megtudni, hova kell küldeni a számlát.
 */
final class BekuldesiCimTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceg = Company::factory()->create();
        $user = User::factory()->create();
        $this->ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($user);
    }

    public function test_megmutatja_a_ceg_bekuldesi_cimet(): void
    {
        config()->set('inbox.mode', 'catchall');
        config()->set('inbox.domain', 'bekuldes.szamlafolyo.hu');
        config()->set('inbox.imap.host', 'mail.pelda.hu');
        config()->set('inbox.imap.username', 'bekuldes@pelda.hu');

        Livewire::test(Beallitasok::class)
            ->assertSee($this->ceg->inbox_token.'@bekuldes.szamlafolyo.hu')
            ->assertDontSee('nincs bekapcsolva');
    }

    /**
     * A legfontosabb állítás ezen a képernyőn: ha a postafiók nincs beállítva a
     * kiszolgálón, a cím **működőnek látszik**, a rá küldött levél viszont
     * sehova nem érkezik meg, és a feladó sem kap hibát.
     */
    public function test_kimondja_ha_a_beerkeztetes_nincs_bekapcsolva(): void
    {
        config()->set('inbox.imap.host', '');
        config()->set('inbox.imap.username', '');

        Livewire::test(Beallitasok::class)
            ->assertSee('A beérkeztetés még nincs bekapcsolva.')
            ->assertSee('nem érkeznek meg');
    }

    /** Plusz-címzésnél a token a plusz után áll. */
    public function test_plusz_cimzesnel_a_token_a_plusz_utan_all(): void
    {
        config()->set('inbox.mode', 'plus');
        config()->set('inbox.plus_address', 'bekuldes@szamlafolyo.hu');

        Livewire::test(Beallitasok::class)
            ->assertSee('bekuldes+'.$this->ceg->inbox_token.'@szamlafolyo.hu');
    }
}
