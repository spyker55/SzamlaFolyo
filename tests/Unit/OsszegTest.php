<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Osszeg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OsszegTest extends TestCase
{
    #[DataProvider('ertelmezhetoErtekek')]
    public function test_ertelmezi_a_ketfele_irasmodot(string $nyers, ?string $vart): void
    {
        $eredmeny = Osszeg::ertelmez($nyers);

        $this->assertTrue($eredmeny->ok, "Nem értelmezte: {$nyers}");
        $this->assertSame($vart, $eredmeny->ertek);
    }

    public static function ertelmezhetoErtekek(): array
    {
        return [
            'magyar, mindkét jellel' => ['1.612.900,25', '1612900.25'],
            'angol, mindkét jellel' => ['1,612,900.25', '1612900.25'],
            'magyar, csak vessző' => ['256,50', '256.50'],
            'sima egész' => ['1500', '1500.00'],
            'nem törhető szóköz csoportosít' => ["1\u{00A0}612\u{00A0}900", '1612900.00'],
            'keskeny nem törhető szóköz' => ["1\u{202F}612\u{202F}900,25", '1612900.25'],
            'sima szóköz csoportosít' => ['1 612 900', '1612900.00'],
            'vesszős csoportosítás tizedes nélkül' => ['1,612,900', '1612900.00'],
            'pontos csoportosítás tizedes nélkül' => ['1.612.900', '1612900.00'],
            'negatív sztornó' => ['-125 000', '-125000.00'],
            'pénznem a végén' => ['12 700 Ft', '12700.00'],
            'üres érték' => ['', null],
        ];
    }

    /**
     * Az egyetlen pont a valóban kétes eset: a `100.000` magyarul százezer, a
     * `256.5` viszont tizedes. Ha ezt elrontjuk, ezerszeres hiba kerül a
     * könyvelői exportba.
     */
    #[DataProvider('ketesPontok')]
    public function test_egyetlen_pont_feloldasa(string $nyers, string $vart): void
    {
        $this->assertSame($vart, Osszeg::ertelmez($nyers)->ertek);
    }

    public static function ketesPontok(): array
    {
        return [
            ['100.000', '100000.00'],   // ezres csoport
            ['12.500', '12500.00'],     // ezres csoport
            ['256.5', '256.50'],        // tizedes
            ['0.500', '0.50'],          // tizedes: a nullával kezdődő nem csoport
            ['1234.567', '1234.57'],    // tizedes, két jegyre kerekítve
        ];
    }

    #[DataProvider('hibasErtekek')]
    public function test_a_hibasat_nem_nyeli_le_csendben(string $nyers): void
    {
        $eredmeny = Osszeg::ertelmez($nyers);

        $this->assertFalse($eredmeny->ok, "Hibásnak kellett volna lennie: {$nyers}");
        $this->assertNull($eredmeny->ertek);
    }

    public static function hibasErtekek(): array
    {
        return [
            'betűk' => ['tizenkétezer'],
            'rossz csoportosítás' => ['12.34.567'],
            'két tizedesjel' => ['1,2,3.4'],
            'szemét' => ['--'],
        ];
    }

    public function test_kiirasa_magyar_irasmod_szerint(): void
    {
        $this->assertSame('1 612 900,25', Osszeg::formaz('1612900.25'));
        $this->assertSame('1 500', Osszeg::formaz('1500.00'));
        $this->assertSame('12 700 HUF', Osszeg::formaz('12700', 'HUF'));
        $this->assertSame('—', Osszeg::formaz(null));
    }
}
