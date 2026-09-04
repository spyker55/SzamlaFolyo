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

    /**
     * A vevő adószáma igazolhatja a vevő nevét — ez a `Konfidencia` kézírás
     * plafonját emeli fel arra az egy mezőre.
     *
     * A fordítottja („ez a bizonylat nem a te cégednek szól") **szándékosan
     * nincs meg**: egy könyvelőiroda több cég bizonylatát dolgozza fel, nála
     * egyik sem a regisztrált cégnek szólna, és minden számla pirosat kapna. Egy
     * validátor, ami egy jogos munkafolyamatra tüzel, rosszabb, mint ha nem
     * lenne — vele veszne a többi piros súlya is.
     */
    public function test_a_sajat_adoszamunk_igazolja_a_vevot(): void
    {
        $this->assertTrue(Validatorok::vevoIgazolt(
            ['customer_tax_number' => '11176165-2-10'],
            '11176165-2-10',
        ));

        $this->assertFalse(Validatorok::vevoIgazolt(
            ['customer_tax_number' => '10773381-2-44'],
            '11176165-2-10',
        ));
    }

    /** Cégadószám vagy vevő-adószám nélkül nincs mit igazolni. */
    public function test_adoszam_nelkul_nincs_igazolas(): void
    {
        $this->assertFalse(Validatorok::vevoIgazolt(['customer_tax_number' => '11176165-2-10'], null));
        $this->assertFalse(Validatorok::vevoIgazolt([], '11176165-2-10'));
    }

    /**
     * Hibás ellenőrző számjegyű adószámra nem építünk következtetést — sem
     * terhelőt, sem mentesítőt. Kézírásnál éppen a félreolvasás a valószínű.
     */
    public function test_a_rossz_adoszam_nem_igazol(): void
    {
        $this->assertFalse(Validatorok::vevoIgazolt(
            ['customer_tax_number' => '12345678-2-42'],
            '12345678-2-42',
        ));
    }

    /**
     * A törzsszám dönt, nem a teljes adószám: az ÁFA-kód és a megyekód
     * változhat, az adóalanyt az első nyolc jegy azonosítja.
     */
    public function test_a_torzsszam_dont_nem_a_teljes_adoszam(): void
    {
        $this->assertTrue(Validatorok::vevoIgazolt(
            ['customer_tax_number' => 'HU 11176165-4-13'],
            '11176165-2-10',
        ));
    }

    // — Idegen bizonylat ————————————————————————————————————

    /**
     * Az egyetlen adat, amit **kívülről** ismerünk: a saját cég adószáma.
     *
     * Ha a bizonylaton ki van töltve a vevő adószáma, és az sem a miénk —
     * miközben a szállító sem mi vagyunk —, akkor ez a papír nem hozzánk
     * tartozik. Ez sokáig némán átment, pedig súlyosabb minden mezőhibánál:
     * nem egy adat rossz, hanem az egész irat téved.
     */
    public function test_az_idegen_bizonylatot_megjeloli(): void
    {
        $bukas = Validatorok::bukottak([
            'supplier_tax_number' => '10773381-2-44',
            'customer_tax_number' => '11176165-2-10',
        ], null, cegAdoszam: '12038538-2-41');

        $this->assertArrayHasKey('customer_tax_number', $bukas);
        $this->assertArrayHasKey('supplier_tax_number', $bukas);
        $this->assertStringContainsString('nem a te cégednek', $bukas['customer_tax_number']);
    }

    /** A saját vevőnk adószámával nincs baj. */
    public function test_a_nekunk_szolo_szamlat_nem_jeloli_meg(): void
    {
        $bukas = Validatorok::bukottak([
            'supplier_tax_number' => '10773381-2-44',
            'customer_tax_number' => '11176165-2-10',
        ], null, cegAdoszam: '11176165-2-10');

        $this->assertArrayNotHasKey('customer_tax_number', $bukas);
    }

    /**
     * Első csapda: a **kimenő** számlán mi vagyunk a szállító, és a vevő
     * adószáma jogosan másé. Ha csak a vevő oldalát néznénk, a saját számláink
     * mind pirosak lennének.
     */
    public function test_a_sajat_kimeno_szamlat_nem_jeloli_meg(): void
    {
        $bukas = Validatorok::bukottak([
            'supplier_tax_number' => '11176165-2-10',
            'customer_tax_number' => '10773381-2-44',
        ], null, cegAdoszam: '11176165-2-10');

        $this->assertArrayNotHasKey('customer_tax_number', $bukas);
    }

    /**
     * Második csapda: a **nyugtán** nincs vevő adószáma, az eladó meg
     * természetesen nem mi vagyunk — mégis a mi költségünk. Ezért csak akkor
     * szólunk, ha a vevő adószáma ki van töltve.
     */
    public function test_a_nyugtat_nem_jeloli_meg(): void
    {
        $bukas = Validatorok::bukottak([
            'supplier_tax_number' => '10773381-2-44',
        ], null, cegAdoszam: '11176165-2-10');

        $this->assertSame([], $bukas);
    }

    /**
     * Harmadik csapda: hibás ellenőrző számjegyű adószámra nem építünk
     * következtetést. Kézírásnál éppen az a valószínű, hogy félreolvasta a
     * számjegyet — nem az, hogy a bizonylat idegen. A rossz számjegy a saját
     * indoklását kapja, nem az idegen bizonylatét.
     */
    public function test_a_rossz_adoszamra_nem_epit_kovetkeztetest(): void
    {
        $bukas = Validatorok::bukottak([
            'supplier_tax_number' => '10773381-2-44',
            'customer_tax_number' => '12345678-2-42',
        ], null, cegAdoszam: '11176165-2-10');

        $this->assertArrayHasKey('customer_tax_number', $bukas);
        $this->assertStringContainsString('ellenőrző számjegye', $bukas['customer_tax_number']);
        $this->assertArrayNotHasKey('supplier_tax_number', $bukas);
    }

    /**
     * Cégadószám nélkül nincs mihez hasonlítani — hallgatunk.
     *
     * Ez egyben a kikapcsoló is: aki több ügyfél iratát egyetlen cégben kezeli,
     * annak üresen kell hagynia az adószámot, különben minden bizonylata piros
     * lenne. Ez a `Beallitasok` képernyőn ki is van írva.
     */
    public function test_ceg_adoszama_nelkul_nem_szolal_meg(): void
    {
        $bukas = Validatorok::bukottak([
            'supplier_tax_number' => '10773381-2-44',
            'customer_tax_number' => '11176165-2-10',
        ]);

        $this->assertSame([], $bukas);
    }
}
