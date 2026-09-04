<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Export\CsvIro;
use App\Services\Export\JsonIro;
use PHPUnit\Framework\TestCase;

final class ExportFormatumTest extends TestCase
{
    /** @return array<int, array<string, string|float|null>> */
    private function sorok(): array
    {
        return [
            [
                'tipus' => 'Számla',
                'szallito' => 'Példa Kft.',
                'szallito_adoszam' => '10773381-2-44',
                'vevo' => null,
                'vevo_adoszam' => null,
                'bizonylatszam' => 'SZ-1',
                'kelt' => '2026-03-14',
                'teljesites' => null,
                'fizetesi_hatarido' => '2026-03-28',
                'netto' => 100000.0,
                'afa' => 27000.0,
                'brutto' => 127000.0,
                'fizetendo' => 127000.0,
                'netto_27' => 100000.0,
                'afa_27' => 27000.0,
                'penznem' => 'HUF',
                'fizetesi_mod' => 'átutalás',
                'konyvelendo' => 'igen',
                'megjegyzes' => null,
                'beerkezes' => '2026-03-15',
                'forras' => 'feltöltés',
                'afa_bontas' => [
                    ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '100000.00', 'afa' => '27000.00'],
                ],
            ],
        ];
    }

    public function test_csv_magyar_excel_nyelvjarasa(): void
    {
        $csv = CsvIro::ir($this->sorok());

        $this->assertStringStartsWith("\u{FEFF}", $csv, 'BOM nélkül az Excel elrontja az ékezeteket.');
        $this->assertStringContainsString('"Szállító adószáma"', $csv);
        $this->assertStringContainsString(';', $csv);
        $this->assertStringContainsString("\r\n", $csv);
        // Tizedesvessző, csoportosítás nélkül — így olvassa számként a magyar Excel.
        $this->assertStringContainsString('127000,00', $csv);
        $this->assertStringNotContainsString('127 000', $csv);
    }

    /**
     * A partner nevét egy modell olvasta ki egy idegen PDF-ből: ha `=`-lel
     * kezdődik, az Excel képletként futtatná.
     */
    public function test_csv_vedi_a_szoveges_cellat_formula_injekcio_ellen(): void
    {
        $sorok = $this->sorok();
        $sorok[0]['szallito'] = '=HYPERLINK("http://rossz.hu","kattints")';

        $csv = CsvIro::ir($sorok);

        $this->assertStringContainsString('"\'=HYPERLINK', $csv);
    }

    /** A számoszlopot viszont nem védjük: ott a sztornó mínusza szöveggé fordulna. */
    public function test_csv_nem_rontja_el_a_negativ_osszeget(): void
    {
        $sorok = $this->sorok();
        $sorok[0]['brutto'] = -127000.0;

        $csv = CsvIro::ir($sorok);

        $this->assertStringContainsString('-127000,00', $csv);
        $this->assertStringNotContainsString("'-127000", $csv);
    }

    /**
     * A kulcsonkénti oszlopok az egész exportbővítés értelme: a könyvelő
     * ezekkel tud számolni. Szövegként kiírva használhatatlanok lennének.
     */
    public function test_csv_szamkent_irja_a_kulcsonkenti_oszlopokat(): void
    {
        $csv = CsvIro::ir($this->sorok());

        $this->assertStringContainsString('"Nettó 27"', $csv);
        $this->assertStringContainsString('"ÁFA egyéb"', $csv);
        $this->assertStringContainsString('27000,00', $csv);

        // Amelyik kulcsra nincs sor, az üresen marad — nem nullával, mert a
        // nulla azt állítaná, hogy volt ilyen adóalap, és éppen semmi.
        $this->assertStringNotContainsString('0,00;0,00', $csv);
    }

    /** A beágyazott lista csak a JSON-ban fér el, a táblázat nem látja. */
    public function test_a_csv_nem_irja_ki_a_beagyazott_bontast(): void
    {
        $csv = CsvIro::ir($this->sorok());

        $this->assertStringNotContainsString('kategoria', $csv);
        $this->assertStringNotContainsString('afa_bontas', $csv);
    }

    /**
     * A kategóriakód nélkül egy nulla százalékos sor értelmezhetetlen: nem
     * derül ki, fordított adózás, mentesség vagy közösségi értékesítés-e. Ez
     * csak a beágyazott alakban fér el.
     */
    public function test_json_viszi_a_teljes_bontast_kategoriakoddal(): void
    {
        $json = json_decode(JsonIro::ir($this->sorok()), true);
        $tetel = $json['tetelek'][0];

        $this->assertSame('S', $tetel['afa_bontas'][0]['kategoria']);
        $this->assertSame('100000.00', $tetel['afa_bontas'][0]['netto']);

        // A lapos oszlopok emellett is ott vannak: aki csak azokat olvassa,
        // ugyanazt a számot kapja.
        $this->assertEqualsWithDelta(27000.0, $tetel['afa_27'], 0.001);
        $this->assertEqualsWithDelta(127000.0, $tetel['fizetendo'], 0.001);
    }

    public function test_json_szamot_ir_szamkent_es_null_t_null_kent(): void
    {
        $nyers = JsonIro::ir($this->sorok(), ['ceg' => 'Teszt']);
        $json = json_decode($nyers, true);

        $this->assertSame('Teszt', $json['ceg']);
        $this->assertNull($json['tetelek'][0]['vevo']);
        $this->assertSame('2026-03-14', $json['tetelek'][0]['kelt']);

        // A szám nem idézőjelben áll: aki gépi úton olvassa, számot kap,
        // nem sztringet, amit neki kellene értelmeznie.
        $this->assertIsNumeric($json['tetelek'][0]['brutto']);
        $this->assertEqualsWithDelta(127000.0, $json['tetelek'][0]['brutto'], 0.001);
        $this->assertMatchesRegularExpression('/"brutto":\s*127000/', $nyers);
        $this->assertStringNotContainsString('"brutto": "', $nyers);
    }
}
