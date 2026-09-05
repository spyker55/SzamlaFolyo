<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Regisztracio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Az ÁSZF elfogadása a regisztrációnál.
 *
 * A jelölőnégyzet a **műveletben** kötelező, nem a gomb letiltásával: egy
 * Livewire-akció közvetlenül is meghívható, a letiltott gomb pedig csak
 * udvariasság. Enélkül nincs mire hivatkozni, ha később vita lesz arról,
 * mit vállalt a felhasználó.
 */
final class AszfElfogadasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['szamlafolyo.regisztracio_nyitva' => true]);
    }

    public function test_elfogadas_nelkul_nincs_fiok(): void
    {
        $this->urlap()
            ->call('regisztracio')
            ->assertHasErrors('aszf');

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_az_uzenet_megmondja_mi_a_teendo(): void
    {
        $komponens = $this->urlap()->call('regisztracio');

        $this->assertSame(
            'A regisztrációhoz el kell fogadnod az Általános Szerződési Feltételeket.',
            $komponens->errors()->first('aszf'),
        );
    }

    public function test_elfogadassal_letrejon_a_fiok(): void
    {
        $this->urlap()
            ->set('aszf', true)
            ->call('regisztracio')
            ->assertHasNoErrors();

        $this->assertSame(1, User::query()->count());
    }

    /** A jelölőnégyzet és az ÁSZF linkje ott van az űrlapon. */
    public function test_az_urlap_kinalja_es_linkeli_az_aszf_et(): void
    {
        $this->get(route('regisztracio'))
            ->assertOk()
            ->assertSee('wire:model="aszf"', false)
            ->assertSee('href="'.route('aszf').'"', false)
            ->assertSee('Általános Szerződési Feltételeket');
    }

    /**
     * Az ÁSZF új lapon nyílik. Enélkül az olvasás elvinné a látogatót az
     * űrlapról, és a már beírt adatok elvesznének — vagyis épp az járna
     * rosszul, aki elolvassa, mielőtt aláírja.
     */
    public function test_az_aszf_link_uj_lapon_nyilik(): void
    {
        $html = $this->get(route('regisztracio'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('aszf'), '/').'"[^>]*target="_blank"[^>]*rel="noopener"/s',
            $html,
        );
    }

    private function urlap(): Testable
    {
        return Livewire::test(Regisztracio::class)
            ->set('nev', 'Új Felhasználó')
            ->set('email', 'uj@example.com')
            ->set('jelszo', 'Nagyon-Hosszu-Jelszo-1')
            ->set('jelszo_megerosites', 'Nagyon-Hosszu-Jelszo-1');
    }
}
