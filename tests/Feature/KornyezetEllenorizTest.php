<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\KornyezetEllenoriz;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A környezet-ellenőrzés.
 *
 * Ez a parancs egy éles hibából nőtt tovább: a cron öt percenként küldött egy
 * levelet „Call to undefined function Symfony\Polyfill\Mbstring\iconv()"
 * szöveggel. Az üzenet egy idegen névteret nevez meg, és semmit nem árul el a
 * valódi okról — hogy a cron PHP-jéből hiányzik az `mbstring` **és** az
 * `iconv` is. A parancs ellenőrizte az `mbstring`-et, de nem az `iconv`-ot, és
 * nem mondta meg, melyik php.ini alapján válaszol: ugyanaz a bináris SSH-ból és
 * cronból más kiterjesztéslistát adhat, és e nélkül az eltérés láthatatlan.
 */
final class KornyezetEllenorizTest extends TestCase
{
    /**
     * Az `iconv` akkor is kötelező, ha a kód sosem hívja közvetlenül: a
     * `symfony/polyfill-mbstring` belül arra támaszkodik. Ha ez a sor kikerül,
     * a hiba visszatér, és megint a semmitmondó üzenet fogad.
     */
    public function test_az_iconv_kotelezo_kiterjesztes(): void
    {
        $lista = (new \ReflectionClass(KornyezetEllenoriz::class))
            ->getConstant('KOTELEZO_KITERJESZTESEK');

        $this->assertArrayHasKey('iconv', $lista);
        $this->assertArrayHasKey('mbstring', $lista);
    }

    /**
     * A betöltött php.ini útvonala nélkül az „ugyanaz a bináris, más eredmény"
     * rejtvény megfejthetetlen — pedig osztott tárhelyen ez a szokásos eset.
     */
    public function test_kiirja_melyik_php_alapjan_valaszol(): void
    {
        Artisan::call('kornyezet:ellenoriz');
        $kimenet = Artisan::output();

        $this->assertStringContainsString(PHP_BINARY, $kimenet);
        $this->assertStringContainsString('php.ini:', $kimenet);
    }
}
