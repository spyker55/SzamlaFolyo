<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cronban a jó hír a némaság.
 *
 * A tárhely időzítője minden kimenetet e-mailben küld el, és két parancs öt
 * percenként fut: napi 288 levél. Két hét alatt mindenki szűrőt tesz rájuk,
 * utána a valódi hibáról szóló levél is a szűrőbe esik — ugyanaz a csapda,
 * mint a mindig piros validátor.
 *
 * A cron sorba írt `> /dev/null` **nem** megoldás: a Laravel a hibaüzenetet is
 * a standard kimenetre írja, tehát az átirányítás a bajt is elnyelné. Ezért a
 * parancsok döntenek, és ezt a döntést őrzi ez a teszt.
 */
final class CsendesCronTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0: string}> */
    public static function cronParancsok(): array
    {
        return [['dokumentum:feldolgoz'], ['fajl:selejtez'], ['tulhasznalat:elszamol']];
    }

    #[DataProvider('cronParancsok')]
    public function test_sikeres_futas_utan_nema(string $parancs): void
    {
        $kod = Artisan::call($parancs);

        $this->assertSame(0, $kod);
        $this->assertSame('', trim(Artisan::output()), "A(z) {$parancs} cronban is kiírt valamit.");
    }

    /** Kézzel futtatva viszont mondja meg, mit csinált — enélkül vaknak tűnne. */
    #[DataProvider('cronParancsok')]
    public function test_reszletes_modban_beszamol(string $parancs): void
    {
        Artisan::call($parancs, ['--verbose' => true]);

        $this->assertNotSame('', trim(Artisan::output()), "A(z) {$parancs} `-v` mellett sem szólalt meg.");
    }

    /** A hiba viszont mindig kimegy — ez az, amiért az értesítési cím létezik. */
    public function test_a_hiba_cronban_is_kimegy(): void
    {
        config(['inbox.imap.host' => '', 'inbox.imap.username' => '']);

        $kod = Artisan::call('email:beolvas');

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('postafiók nem olvasható', Artisan::output());
    }
}
