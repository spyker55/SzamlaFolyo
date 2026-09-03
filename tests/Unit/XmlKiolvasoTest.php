<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Xml\XmlKiolvaso;
use PHPUnit\Framework\TestCase;
use Tests\Support\XmlFixtura;

/**
 * A lánc legolcsóbb foka. Ha ez működik, egy e-számla feldolgozása nem kerül
 * semmibe és nem is találgatás.
 */
final class XmlKiolvasoTest extends TestCase
{
    private XmlKiolvaso $kiolvaso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kiolvaso = new XmlKiolvaso;
    }

    public function test_cii_szamlat_kiolvas(): void
    {
        $eredmeny = $this->kiolvaso->ertelmez(XmlFixtura::cii());

        $this->assertNotNull($eredmeny);
        $this->assertSame('xml/cii', $eredmeny['nev']);

        $mezok = $eredmeny['nyers'];
        $this->assertSame('szamla', $mezok['doc_type']);
        $this->assertSame('SZ-2026-0042', $mezok['doc_number']);
        $this->assertSame('Példa Szállító Kft.', $mezok['supplier_name']);
        $this->assertSame('Vevő Zrt.', $mezok['customer_name']);
        $this->assertSame('2026-03-14', $mezok['issue_date']);
        $this->assertSame('2026-03-15', $mezok['fulfillment_date']);
        $this->assertSame('2026-03-28', $mezok['due_date']);
        $this->assertSame('HUF', $mezok['currency']);
        $this->assertSame('átutalás', $mezok['payment_method']);
    }

    /**
     * A végösszegeknek a fejléc-összesítőből kell jönniük. A tételsorok alatt
     * ugyanilyen nevű elemek állnak — egy pontatlan útvonal azokba akadna bele,
     * és egy tételsor összegét írná be végösszegnek.
     */
    public function test_cii_a_fejlec_vegosszegeit_veszi(): void
    {
        $mezok = $this->kiolvaso->ertelmez(XmlFixtura::cii())['nyers'];

        $this->assertSame(100000.0, $mezok['net_amount']);
        $this->assertSame(24800.0, $mezok['vat_amount']);
        $this->assertSame(124800.0, $mezok['gross_amount']);
        $this->assertSame(124800.0, $mezok['fizetendo']);
    }

    /** A tételsor alatti ÁFA-elem nem kerülhet a bontásba. */
    public function test_cii_bontasa_kulcsonkent_all(): void
    {
        $bontas = $this->kiolvaso->ertelmez(XmlFixtura::cii())['nyers']['afa_bontas'];

        $this->assertSame([
            ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => 90000.0, 'afa' => 24300.0],
            ['kulcs' => 5.0, 'kategoria' => 'S', 'netto' => 10000.0, 'afa' => 500.0],
        ], $bontas);
    }

    /** Két adószám közül az ÁFA-számot (`VA`) kell választani. */
    public function test_cii_az_afa_szamot_valasztja(): void
    {
        $mezok = $this->kiolvaso->ertelmez(XmlFixtura::cii())['nyers'];

        $this->assertSame('HU10773381', $mezok['supplier_tax_number']);
    }

    public function test_ubl_szamlat_kiolvas(): void
    {
        $eredmeny = $this->kiolvaso->ertelmez(XmlFixtura::ubl());

        $this->assertNotNull($eredmeny);
        $this->assertSame('xml/ubl', $eredmeny['nev']);

        $mezok = $eredmeny['nyers'];
        $this->assertSame('szamla', $mezok['doc_type']);
        $this->assertSame('SZ-2026-0042', $mezok['doc_number']);
        $this->assertSame('2026-03-14', $mezok['issue_date']);
        $this->assertSame('2026-03-15', $mezok['fulfillment_date']);
        $this->assertSame('2026-03-28', $mezok['due_date']);
        $this->assertSame('HUF', $mezok['currency']);
        $this->assertSame('10773381-2-44', $mezok['supplier_tax_number']);
        $this->assertSame('10537914-4-44', $mezok['customer_tax_number']);
        $this->assertSame('átutalás', $mezok['payment_method']);
    }

    /** A bejegyzett cégnév a pontosabb, nem a rövidített kereskedelmi név. */
    public function test_ubl_a_bejegyzett_cegnevet_veszi(): void
    {
        $mezok = $this->kiolvaso->ertelmez(XmlFixtura::ubl())['nyers'];

        $this->assertSame('Példa Szállító Kft.', $mezok['supplier_name']);
    }

    /**
     * A tételsornak (`InvoiceLine`) saját `TaxTotal`-ja van saját `TaxAmount`-tal.
     * A fejléc ÁFÁ-jának a dokumentum szintű elemből kell jönnie.
     */
    public function test_ubl_nem_a_tetelsor_afajat_veszi(): void
    {
        $mezok = $this->kiolvaso->ertelmez(XmlFixtura::ubl())['nyers'];

        $this->assertSame(100000.0, $mezok['net_amount']);
        $this->assertSame(24800.0, $mezok['vat_amount']);
        $this->assertSame(124800.0, $mezok['gross_amount']);
        $this->assertSame(124800.0, $mezok['fizetendo']);
    }

    public function test_ubl_bontasa_kulcsonkent_all(): void
    {
        $bontas = $this->kiolvaso->ertelmez(XmlFixtura::ubl())['nyers']['afa_bontas'];

        $this->assertSame([
            ['kulcs' => 27.0, 'kategoria' => 'S', 'netto' => 90000.0, 'afa' => 24300.0],
            ['kulcs' => 5.0, 'kategoria' => 'S', 'netto' => 10000.0, 'afa' => 500.0],
        ], $bontas);
    }

    /** A jóváíró számlának saját gyökereleme van, típuskód nélkül is felismerhető. */
    public function test_ubl_jovairo_szamlat_felismer(): void
    {
        $xml = str_replace(
            ['<Invoice ', '</Invoice>', '<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>'],
            ['<CreditNote ', '</CreditNote>', ''],
            XmlFixtura::ubl(),
        );

        $mezok = $this->kiolvaso->ertelmez($xml)['nyers'];

        $this->assertSame('sztorno_szamla', $mezok['doc_type']);
    }

    /** Ismeretlen típuskódra nem tippelünk — az embernek kell kiválasztania. */
    public function test_ismeretlen_tipuskodra_nem_tippel(): void
    {
        $xml = str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>999</ram:TypeCode>', XmlFixtura::cii());

        $mezok = $this->kiolvaso->ertelmez($xml)['nyers'];

        $this->assertNull($mezok['doc_type']);
    }

    /** A strukturált adat nem találgatás: amit megtalált, abban biztos. */
    public function test_a_kitoltott_mezok_magabiztossaga_teljes(): void
    {
        $nyers = $this->kiolvaso->ertelmez(XmlFixtura::cii())['nyers'];

        $this->assertSame(1.0, $nyers['confidence']['doc_number']);
        $this->assertSame(1.0, $nyers['confidence']['afa_bontas']);
        $this->assertFalse($nyers['tobb_irat_gyanu']);
    }

    public function test_amit_nem_ismer_fel_azt_a_modellre_hagyja(): void
    {
        $this->assertNull($this->kiolvaso->ertelmez(''));
        $this->assertNull($this->kiolvaso->ertelmez('ez nem xml'));
        $this->assertNull($this->kiolvaso->ertelmez('<?xml version="1.0"?><valami/>'));

        // Gyökérnév stimmel, de a névtér nem UBL — nem e-számla.
        $this->assertNull($this->kiolvaso->ertelmez('<?xml version="1.0"?><Invoice><ID>1</ID></Invoice>'));
    }

    public function test_a_tul_nagy_fajlt_elutasitja(): void
    {
        $tulNagy = str_pad(XmlFixtura::ubl(), XmlKiolvaso::MAX_BAJT + 1, ' ');

        $this->assertNull($this->kiolvaso->ertelmez($tulNagy));
    }
}
