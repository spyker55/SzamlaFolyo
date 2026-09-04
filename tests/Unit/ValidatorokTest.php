<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Validatorok;
use PHPUnit\Framework\TestCase;

/**
 * A determinisztikus ellenőrzések. Ezek nem javítanak semmit — csak megmondják,
 * melyik mezőben van ellentmondás, és onnan a konfidencia lefelé húzódik.
 *
 * A modell önbevallott magabiztossága rosszul kalibrált: magabiztos akkor is,
 * amikor téved. Ezért kell mellé olyan jel, ami a papírtól független.
 */
final class ValidatorokTest extends TestCase
{
    public function test_a_netto_es_afa_kiadja_a_bruttot(): void
    {
        $bukas = Validatorok::bukottak([
            'net_amount' => '1000.00',
            'vat_amount' => '270.00',
            'gross_amount' => '1270.00',
        ]);

        $this->assertSame([], $bukas);
    }

    public function test_a_rossz_brutto_mindharom_mezot_jelzi(): void
    {
        $bukas = Validatorok::bukottak([
            'net_amount' => '1000.00',
            'vat_amount' => '270.00',
            'gross_amount' => '9999.00',
        ]);

        $this->assertArrayHasKey('net_amount', $bukas);
        $this->assertArrayHasKey('vat_amount', $bukas);
        $this->assertArrayHasKey('gross_amount', $bukas);
    }

    public function test_a_hibas_adoszamot_megfogja(): void
    {
        $bukas = Validatorok::bukottak(['supplier_tax_number' => '12345678-2-42']);

        $this->assertArrayHasKey('supplier_tax_number', $bukas);
    }

    public function test_a_hatarido_nem_elozheti_meg_a_keltet(): void
    {
        $bukas = Validatorok::bukottak([
            'issue_date' => '2026-03-14',
            'due_date' => '2026-03-01',
        ]);

        $this->assertArrayHasKey('due_date', $bukas);
        $this->assertArrayHasKey('issue_date', $bukas);
    }

    public function test_a_jo_bontas_atmegy(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '5000.00', 'vat_amount' => '1130.00', 'gross_amount' => '6130.00'],
            [
                ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '4000.00', 'afa' => '1080.00'],
                ['kulcs' => 5.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '50.00'],
            ],
        );

        $this->assertSame([], $bukas);
    }

    /**
     * Ez a bontás legnagyobb haszna. Pontosan ez a hiba fordult elő élesben:
     * a modell egy tételsor nettóját írta be végösszegnek. A fejlécből magából
     * ez nem derül ki — a sorok összegéből igen.
     */
    public function test_a_bontas_megfogja_a_rossz_fejlec_vegosszeget(): void
    {
        $bukas = Validatorok::bukottak(
            // A nettó egy tételsoré, nem a végösszeg — a bruttó viszont stimmel
            // hozzá, tehát a meglévő számtani ellenőrzés átengedné.
            ['net_amount' => '1000.00', 'vat_amount' => '270.00', 'gross_amount' => '1270.00'],
            [
                ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '4000.00', 'afa' => '1080.00'],
                ['kulcs' => 5.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '50.00'],
            ],
        );

        $this->assertArrayHasKey('afa_bontas', $bukas);
        $this->assertArrayHasKey('net_amount', $bukas);
        $this->assertArrayHasKey('vat_amount', $bukas);
    }

    public function test_a_kulcsbol_ki_kell_jonnie_az_afanak(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '1000.00', 'vat_amount' => '50.00', 'gross_amount' => '1050.00'],
            [['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '50.00']],
        );

        $this->assertArrayHasKey('afa_bontas', $bukas);
    }

    /** A kerekítés miatt egy egységnyi eltérés még nem hiba. */
    public function test_a_kerekitest_elviseli(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '1000.00', 'vat_amount' => '270.00', 'gross_amount' => '1270.00'],
            [['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '270.40']],
        );

        $this->assertSame([], $bukas);
    }

    public function test_a_forditott_adozasban_nem_lehet_afa(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '1000.00', 'vat_amount' => '270.00', 'gross_amount' => '1270.00'],
            [['kulcs' => 27.0, 'kategoria' => 'AE', 'netto' => '1000.00', 'afa' => '270.00']],
        );

        $this->assertArrayHasKey('afa_bontas', $bukas);
    }

    public function test_a_forditott_adozas_nulla_afaval_rendben_van(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '1000.00', 'vat_amount' => '0.00', 'gross_amount' => '1000.00'],
            [['kulcs' => 0.0, 'kategoria' => 'AE', 'netto' => '1000.00', 'afa' => '0.00']],
        );

        $this->assertSame([], $bukas);
    }

    /** Ugyanaz a kulcs kétszer: a modell tételsorokat sorolt fel, nem összesített. */
    public function test_az_ismetlodo_kulcsot_jelzi(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '2000.00', 'vat_amount' => '540.00', 'gross_amount' => '2540.00'],
            [
                ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '270.00'],
                ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => '1000.00', 'afa' => '270.00'],
            ],
        );

        $this->assertArrayHasKey('afa_bontas', $bukas);
    }

    public function test_bontas_nelkul_nem_panaszkodik(): void
    {
        $bukas = Validatorok::bukottak(
            ['net_amount' => '1000.00', 'vat_amount' => '270.00', 'gross_amount' => '1270.00'],
            null,
        );

        $this->assertSame([], $bukas);
    }
}
