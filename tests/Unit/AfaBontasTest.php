<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AfaBontas;
use PHPUnit\Framework\TestCase;

/**
 * A bontás számtana. Három hívója van — a kiolvasó, az ellenőrző képernyő és
 * az export —, és pontosan azért egy osztály, hogy a három ne számolhasson
 * másképp.
 */
final class AfaBontasTest extends TestCase
{
    /**
     * Ugyanez az értelmező kapja a modell válaszát és az ember gépelését. Az
     * ember odaírja a százalékjelet, és vesszővel tizedesel.
     */
    public function test_a_kulcsot_mindenfele_alakban_erti(): void
    {
        $this->assertSame(27.0, AfaBontas::kulcsErtelmez('27%'));
        $this->assertSame(27.0, AfaBontas::kulcsErtelmez('27,0'));
        $this->assertSame(27.0, AfaBontas::kulcsErtelmez(27));
        $this->assertSame(7.5, AfaBontas::kulcsErtelmez('7,5 %'));
        $this->assertSame(0.0, AfaBontas::kulcsErtelmez('0'));
    }

    /** Amit nem értünk, az null — nem nulla. A nulla kulcs értelmes állítás. */
    public function test_az_ertelmezhetetlen_kulcs_null(): void
    {
        $this->assertNull(AfaBontas::kulcsErtelmez('huszonhét'));
        $this->assertNull(AfaBontas::kulcsErtelmez(''));
        $this->assertNull(AfaBontas::kulcsErtelmez(null));
    }

    /**
     * A bruttót sosem tároljuk (az EN 16931 sem), soronként itt áll elő. ÁFA
     * nélkül a nettó önmaga a bruttó — fordított adózásnál ez a normális.
     */
    public function test_a_brutto_szamolt_ertek(): void
    {
        $this->assertSame('1270.00', AfaBontas::brutto('1000.00', '270.00'));
        $this->assertSame('1000.00', AfaBontas::brutto('1000.00', null));
        $this->assertSame('1270.50', AfaBontas::brutto('1 000,50', '270'));
    }

    /** Adóalap nélkül nincs sor: a puszta ÁFA-összeg nem bontássor. */
    public function test_adoalap_nelkul_nincs_brutto(): void
    {
        $this->assertNull(AfaBontas::brutto(null, '270.00'));
        $this->assertNull(AfaBontas::brutto('', '270.00'));
    }

    public function test_kulcsonkenti_oszlopokba_sorol(): void
    {
        $vodrok = AfaBontas::vodrok([
            ['kulcs' => 27, 'netto' => '1000.00', 'afa' => '270.00'],
            ['kulcs' => 5, 'netto' => '500.00', 'afa' => '25.00'],
        ]);

        $this->assertSame(1000.0, $vodrok['netto_27']);
        $this->assertSame(270.0, $vodrok['afa_27']);
        $this->assertSame(500.0, $vodrok['netto_5']);
        $this->assertSame(25.0, $vodrok['afa_5']);

        // Amihez nincs sor, az üres marad — nem nulla. A nulla azt állítaná,
        // hogy volt ilyen kulcs, és éppen semmi nem esett rá.
        $this->assertNull($vodrok['netto_18']);
        $this->assertNull($vodrok['netto_egyeb']);
    }

    /**
     * Emberi szerkesztés után ugyanaz a kulcs több sorban is szerepelhet — két
     * 27%-os sor összege továbbra is egyetlen 27%-os adóalap.
     */
    public function test_az_azonos_kulcsu_sorokat_osszevonja(): void
    {
        $vodrok = AfaBontas::vodrok([
            ['kulcs' => 27, 'netto' => '1000.00', 'afa' => '270.00'],
            ['kulcs' => 27, 'netto' => '2000.00', 'afa' => '540.00'],
        ]);

        $this->assertSame(3000.0, $vodrok['netto_27']);
        $this->assertSame(810.0, $vodrok['afa_27']);
    }

    /** A nem magyar kulcs (külföldi számla 19%-a) az „egyéb" párba megy. */
    public function test_az_ismeretlen_kulcs_az_egyebbe_kerul(): void
    {
        $vodrok = AfaBontas::vodrok([['kulcs' => 19, 'netto' => '1000.00', 'afa' => '190.00']]);

        $this->assertSame(1000.0, $vodrok['netto_egyeb']);
        $this->assertSame(190.0, $vodrok['afa_egyeb']);
    }

    /**
     * A nulla vödörnek nincs ÁFA-oszlopa — nullától nem keletkezik adó. Ha
     * mégis van a soron, akkor vagy a kulcs rossz, vagy az összeg: a sor
     * egészében az „egyéb"-be megy, mert pénzt csendben elnyelni nem szabad.
     *
     * Ez a szabály könnyen visszafejlődik, ezért van rá külön teszt.
     */
    public function test_a_nulla_kulcson_levo_afa_nem_tunik_el(): void
    {
        $vodrok = AfaBontas::vodrok([['kulcs' => 0, 'netto' => '1000.00', 'afa' => '80.00']]);

        $this->assertNull($vodrok['netto_0']);
        $this->assertSame(1000.0, $vodrok['netto_egyeb']);
        $this->assertSame(80.0, $vodrok['afa_egyeb']);
    }

    /** A valódi nulla kulcsos sor viszont a helyén marad. */
    public function test_a_nulla_kulcsos_sor_a_nulla_oszlopba_megy(): void
    {
        $vodrok = AfaBontas::vodrok([['kulcs' => 0, 'netto' => '1000.00', 'afa' => null]]);

        $this->assertSame(1000.0, $vodrok['netto_0']);
        $this->assertNull($vodrok['netto_egyeb']);
    }

    public function test_bontas_nelkul_minden_oszlop_ures(): void
    {
        $this->assertSame(array_fill_keys(AfaBontas::OSZLOPOK, null), AfaBontas::vodrok(null));
    }

    /**
     * A `json_encode(27.0)` „27"-et ír, tehát az egész kulcs int-ként jön
     * vissza az adatbázisból, a törtes float-ként. Egyik sem téveszthet meg.
     */
    public function test_a_json_korut_utani_tipusok_sem_zavarjak(): void
    {
        $vodrok = AfaBontas::vodrok(json_decode((string) json_encode([
            ['kulcs' => 27.0, 'netto' => '1000.00', 'afa' => '270.00'],
        ]), true));

        $this->assertSame(1000.0, $vodrok['netto_27']);
    }
}
