<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\Concerns\CsendesCron;
use Illuminate\Console\Command;
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

    /**
     * A hiba viszont mindig kimegy — ez az, amiért az értesítési cím létezik.
     *
     * Magát a tulajdonságot ellenőrizzük, egy eldobható parancson, nem egy
     * konkrét parancs hibaágát. Eddig az `email:beolvas` szolgáltatta a
     * hibát; azzal együtt a teszt is elveszett volna, pedig nem az a parancs
     * volt a lényeg, hanem hogy a `CsendesCron` az **összegzést** hallgatja
     * el, a hibát soha.
     */
    public function test_a_hiba_cronban_is_kimegy(): void
    {
        Artisan::registerCommand(new class extends Command
        {
            use CsendesCron;

            protected $signature = 'teszt:csendes-hiba';

            public function handle(): int
            {
                $this->osszegzes('Ez cronban néma.');
                $this->error('Ez viszont mindig kimegy.');

                return self::FAILURE;
            }
        });

        $kod = Artisan::call('teszt:csendes-hiba');
        $kimenet = Artisan::output();

        $this->assertSame(1, $kod);
        $this->assertStringNotContainsString('Ez cronban néma.', $kimenet);
        $this->assertStringContainsString('Ez viszont mindig kimegy.', $kimenet);
    }
}
