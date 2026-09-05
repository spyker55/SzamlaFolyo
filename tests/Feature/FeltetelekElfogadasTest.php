<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Regisztracio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A feltételek elfogadása a regisztrációnál.
 *
 * Egy jelölés, két dokumentum: az ÁSZF-et elfogadja az ember, az adatkezelési
 * tájékoztatót megismeri — a kettő nem ugyanaz az ige, mert a tájékoztató nem
 * szerződés.
 *
 * A jelölőnégyzet a **műveletben** kötelező, nem a gomb letiltásával: egy
 * Livewire-akció közvetlenül is meghívható, a letiltott gomb pedig csak
 * udvariasság. Enélkül nincs mire hivatkozni, ha később vita lesz arról,
 * mit vállalt a felhasználó.
 */
final class FeltetelekElfogadasTest extends TestCase
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
            ->assertHasErrors('feltetelek');

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_az_uzenet_megmondja_mi_a_teendo(): void
    {
        $komponens = $this->urlap()->call('regisztracio');

        $this->assertSame(
            'A regisztrációhoz el kell fogadnod az ÁSZF-et és az adatkezelési tájékoztatót.',
            $komponens->errors()->first('feltetelek'),
        );
    }

    public function test_elfogadassal_letrejon_a_fiok(): void
    {
        $this->urlap()
            ->set('feltetelek', true)
            ->call('regisztracio')
            ->assertHasNoErrors();

        $this->assertSame(1, User::query()->count());
    }

    /** A jelölőnégyzet és mindkét dokumentum linkje ott van az űrlapon. */
    public function test_az_urlap_mindket_dokumentumot_linkeli(): void
    {
        $this->get(route('regisztracio'))
            ->assertOk()
            ->assertSee('wire:model="feltetelek"', false)
            ->assertSee('href="'.route('aszf').'"', false)
            ->assertSee('href="'.route('adatkezeles').'"', false)
            ->assertSee('Általános Szerződési Feltételeket')
            ->assertSee('Adatkezelési tájékoztatót');
    }

    /**
     * Mindkét link új lapon nyílik. Enélkül az olvasás elvinné a látogatót az
     * űrlapról, és a már beírt adatok elvesznének — vagyis épp az járna
     * rosszul, aki elolvassa, mielőtt aláírja.
     */
    #[DataProvider('dokumentumok')]
    public function test_a_dokumentumok_uj_lapon_nyilnak(string $utvonal): void
    {
        $html = $this->get(route('regisztracio'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route($utvonal), '/').'"[^>]*target="_blank"[^>]*rel="noopener"/s',
            $html,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function dokumentumok(): array
    {
        return ['ÁSZF' => ['aszf'], 'adatkezelés' => ['adatkezeles']];
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
