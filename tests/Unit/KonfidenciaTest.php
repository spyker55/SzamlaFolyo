<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Konfidencia;
use App\Services\Extraction\Sema;
// A küszöböket a `sav()` a konfigurációból olvassa, ezért bootolt alkalmazás
// kell hozzá — adatbázis viszont nem.
use Tests\TestCase;

/**
 * A két jel összevonása egy számmá.
 *
 * Az irány egyirányú: a determinisztikus validátor **csak lefelé húzhat**. Ez
 * nem ízlés kérdése — két valódi számlán mérve egyik modell önbevallása sem
 * találta el a tényleges hibát: az egyik 0,5-öt adott egy jól kiolvasott
 * mezőre, a másik 0,95-öt a saját tévedésére.
 */
final class KonfidenciaTest extends TestCase
{
    /**
     * A hiányzó magabiztosság nem magas magabiztosság. Korábban mindkettő
     * „biztos"-nak látszott, így egy néma modell ugyanolyan megnyugtató volt,
     * mint egy magabiztos.
     */
    public function test_a_hianyzo_pontszam_kulon_allapot(): void
    {
        $this->assertSame('nincs_adat', Konfidencia::sav(null));
        $this->assertSame('biztos', Konfidencia::sav(1.0));
    }

    public function test_a_kuszobok_szerint_savol(): void
    {
        $this->assertSame('gyanus', Konfidencia::sav(0.2));
        $this->assertSame('bizonytalan', Konfidencia::sav(0.7));
        $this->assertSame('biztos', Konfidencia::sav(0.9));
    }

    /**
     * A határérték az óvatosabb sávba esik.
     *
     * A modellek kerek számokat mondanak, és a 0,85 az egyik kedvencük — egy
     * kézzel írott számlán a 3.8 Flash pontosan ennyit adott a szállító nevére,
     * ami a lap legalacsonyabb értéke és az egyetlen rossz mező volt. Szigorú
     * `<` mellett ez jelöletlenül ment volna át.
     */
    public function test_a_hataron_allo_ertek_a_szigorubb_savba_esik(): void
    {
        $this->assertSame('bizonytalan', Konfidencia::sav(0.85));
        $this->assertSame('biztos', Konfidencia::sav(0.851));
        $this->assertSame('gyanus', Konfidencia::sav(0.5));
    }

    /** A bukott validátor a magabiztos mezőt is a piros sávba húzza. */
    public function test_a_validator_csak_lefele_huzhat(): void
    {
        $eredmeny = Konfidencia::osszevon(
            ['net_amount' => 0.99],
            ['net_amount' => 'A nettó és az ÁFA összege nem adja ki a bruttót.'],
            ['net_amount' => '1000.00'],
        );

        $this->assertLessThanOrEqual(Konfidencia::BUKAS_PLAFON, $eredmeny['combined']['net_amount']);
        $this->assertSame('gyanus', Konfidencia::sav($eredmeny['combined']['net_amount']));
    }

    /** Felfelé viszont soha: a hibátlan ellenőrzés nem tesz biztossá semmit. */
    public function test_a_validator_nem_emel(): void
    {
        $eredmeny = Konfidencia::osszevon(['net_amount' => 0.4], [], ['net_amount' => '1000.00']);

        $this->assertSame(0.4, $eredmeny['combined']['net_amount']);
    }

    /**
     * Az üres mezőnek nincs értelmes magabiztossága: nincs mit ellenőrizni
     * rajta, és nem is szabad pirosnak látszania.
     */
    public function test_az_ures_mezo_kimarad(): void
    {
        $eredmeny = Konfidencia::osszevon(['doc_number' => 0.9], [], ['doc_number' => null]);

        $this->assertArrayNotHasKey('doc_number', $eredmeny['combined']);
    }

    /** Amiről a modell nem nyilatkozott, azt nem tekintjük biztosnak. */
    public function test_a_nem_ertekelt_mezo_kozepre_esik(): void
    {
        $eredmeny = Konfidencia::osszevon([], [], ['doc_number' => 'SZ-1']);

        $this->assertSame(0.5, $eredmeny['combined']['doc_number']);
    }

    /** Az ÁFA-bontás nem skalár, ezért külön kerül be — de ugyanúgy bekerül. */
    public function test_az_afa_bontas_is_kap_pontszamot(): void
    {
        $eredmeny = Konfidencia::osszevon(
            ['afa_bontas' => 0.8],
            [],
            ['afa_bontas' => [['kulcs' => 27, 'netto' => '1000.00']]],
        );

        $this->assertSame(0.8, $eredmeny['combined']['afa_bontas']);
        $this->assertNotContains('afa_bontas', Sema::MEZOK);
    }
}
