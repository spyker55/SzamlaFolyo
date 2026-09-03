<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Forras\Felderito;
use App\Services\Extraction\Forras\Jelleg;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfFixtura;

/**
 * A feldolgozási lánc első lépése: honnan olvasható ki a legolcsóbban ez a
 * fájl. Ami strukturáltan is megvan, azt ne küldjük drága modellbe.
 */
final class FelderitoTest extends TestCase
{
    private Felderito $felderito;

    protected function setUp(): void
    {
        parent::setUp();
        $this->felderito = new Felderito;
    }

    public function test_onallo_xml_e_szamlat_felismer(): void
    {
        $xml = '<?xml version="1.0"?><Invoice><ID>SZ-1</ID></Invoice>';

        $eredmeny = $this->felderito->felderit($xml, 'application/xml');

        $this->assertSame(Jelleg::StrukturaltXml, $eredmeny->jelleg);
        $this->assertSame($xml, $eredmeny->xml);
        $this->assertFalse($eredmeny->jelleg->igenyelModellt());
    }

    /** A MIME-típusra nem hagyatkozunk: a levelezők gyakran rosszul mondják meg. */
    public function test_xml_t_a_tartalombol_is_felismer(): void
    {
        $xml = '<?xml version="1.0"?><Invoice/>';

        $this->assertSame(
            Jelleg::StrukturaltXml,
            $this->felderito->felderit($xml, 'application/octet-stream')->jelleg,
        );
    }

    /**
     * Ez a legfontosabb eset: kívülről sima PDF, belül viszont ott az adat.
     * Ilyet OCR-ezni tiszta veszteség.
     */
    public function test_pdf_be_agyazott_xml_t_megtalal(): void
    {
        $xml = '<?xml version="1.0"?><rsm:CrossIndustryInvoice><ID>DV-2025/1170</ID></rsm:CrossIndustryInvoice>';

        $eredmeny = $this->felderito->felderit(PdfFixtura::beagyazottXmlLel($xml), 'application/pdf');

        $this->assertSame(Jelleg::BeagyazottXml, $eredmeny->jelleg);
        $this->assertStringContainsString('DV-2025/1170', (string) $eredmeny->xml);
        $this->assertFalse($eredmeny->jelleg->igenyelModellt());
    }

    /** A valódi Factur-X fájlokban a melléklet tömörítve van. */
    public function test_tomoritett_beagyazott_xml_t_is_kibontja(): void
    {
        $xml = '<?xml version="1.0"?><Invoice><ID>TOMOR-42</ID></Invoice>';

        $eredmeny = $this->felderito->felderit(
            PdfFixtura::beagyazottXmlLel($xml, tomoritve: true),
            'application/pdf',
        );

        $this->assertSame(Jelleg::BeagyazottXml, $eredmeny->jelleg);
        $this->assertStringContainsString('TOMOR-42', (string) $eredmeny->xml);
    }

    public function test_szovegreteges_pdf_t_felismer(): void
    {
        $eredmeny = $this->felderito->felderit(PdfFixtura::szovegreteggel(), 'application/pdf');

        $this->assertSame(Jelleg::Szovegreteg, $eredmeny->jelleg);
        $this->assertStringContainsString('DV-2025/1170', (string) $eredmeny->szoveg);
        $this->assertGreaterThan(200, $eredmeny->szovegHossz);
    }

    /**
     * Egy szkennelt PDF-en is van néhány karakter — oldalszám, a szkennelő
     * fejléce. Az nem szövegréteg, és nem szabad annak látszania.
     */
    public function test_a_par_karakteres_pdf_nem_szovegreteg(): void
    {
        $eredmeny = $this->felderito->felderit(PdfFixtura::szovegNelkul(), 'application/pdf');

        $this->assertSame(Jelleg::Kep, $eredmeny->jelleg);
        $this->assertTrue($eredmeny->jelleg->igenyelModellt());
    }

    public function test_kepet_kepnek_lat(): void
    {
        $this->assertSame(
            Jelleg::Kep,
            $this->felderito->felderit("\xFF\xD8\xFF\xE0 jpeg bájtok", 'image/jpeg')->jelleg,
        );
    }

    /** A sérült PDF-et nem dobjuk el: képként még elolvashatja a modell. */
    public function test_serult_pdf_eseten_kepre_esik_vissza(): void
    {
        $eredmeny = $this->felderito->felderit('%PDF-1.7 ez nem egy valódi pdf', 'application/pdf');

        $this->assertSame(Jelleg::Kep, $eredmeny->jelleg);
        $this->assertNotNull($eredmeny->hiba);
    }
}
