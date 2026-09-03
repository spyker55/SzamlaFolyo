<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Sema;
use PHPUnit\Framework\TestCase;

/**
 * Az ÁFA-bontás alakra hozása. A séma itt csak a **szerkezetet** rendezi el —
 * az értékek értelmezése (kulcs számmá, összegek a mi alakunkra) a Kiolvasóban
 * történik, ugyanott, ahol a többi összegé.
 */
final class SemaBontasTest extends TestCase
{
    public function test_a_jo_sor_atmegy(): void
    {
        $bontas = Sema::tisztitBontas([
            ['kulcs' => 27, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270],
        ]);

        $this->assertSame([
            ['kulcs' => 27, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270],
        ], $bontas);
    }

    public function test_ismeretlen_kategoria_null_lesz(): void
    {
        $bontas = Sema::tisztitBontas([
            ['kulcs' => 27, 'kategoria' => 'XYZ', 'netto' => 1000, 'afa' => 270],
        ]);

        // A sor megmarad — a kulcs és az összeg használható. Csak a kitalált
        // kategóriakódot dobjuk el, mert abból hibás könyvelés lenne.
        $this->assertNotNull($bontas);
        $this->assertNull($bontas[0]['kategoria']);
        $this->assertSame(27, $bontas[0]['kulcs']);
    }

    /** Kulcs vagy adóalap nélkül a sor se nem könyvelhető, se nem ellenőrizhető. */
    public function test_a_hianyos_sor_kiesik(): void
    {
        $bontas = Sema::tisztitBontas([
            ['kulcs' => null, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270],
            ['kulcs' => 5, 'kategoria' => 'S', 'netto' => null, 'afa' => 50],
            ['kulcs' => 27, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270],
        ]);

        $this->assertNotNull($bontas);
        $this->assertCount(1, $bontas);
        $this->assertSame(27, $bontas[0]['kulcs']);
    }

    public function test_a_nem_tomb_sor_kiesik(): void
    {
        $bontas = Sema::tisztitBontas([
            'ez nem sor',
            ['kulcs' => 27, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270],
        ]);

        $this->assertNotNull($bontas);
        $this->assertCount(1, $bontas);
    }

    public function test_ismeretlen_kulcsokat_eldob_a_sorbol(): void
    {
        $bontas = Sema::tisztitBontas([
            ['kulcs' => 27, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270, 'valami' => 'kitalált'],
        ]);

        $this->assertNotNull($bontas);
        $this->assertSame(['kulcs', 'kategoria', 'netto', 'afa'], array_keys($bontas[0]));
    }

    /**
     * Ennél hosszabb lista azt jelenti, hogy a modell tételsorokat sorolt fel
     * kulcsonkénti összesítés helyett — azt nem tároljuk el.
     */
    public function test_a_sorok_szama_korlatozott(): void
    {
        $sok = array_fill(0, 50, ['kulcs' => 27, 'kategoria' => 'S', 'netto' => 10, 'afa' => 2.7]);

        $this->assertCount(Sema::BONTAS_MAX_SOR, (array) Sema::tisztitBontas($sok));
    }

    public function test_az_ures_bontas_null(): void
    {
        $this->assertNull(Sema::tisztitBontas(null));
        $this->assertNull(Sema::tisztitBontas([]));
        $this->assertNull(Sema::tisztitBontas('nem tömb'));
        $this->assertNull(Sema::tisztitBontas([['kulcs' => null, 'netto' => null]]));
    }

    /** A bontás a `tisztit()` teljes körén is átjön, nem csak külön hívva. */
    public function test_a_tisztit_visszaadja_a_bontast(): void
    {
        $tiszta = Sema::tisztit([
            'doc_type' => 'szamla',
            'net_amount' => 1000,
            'afa_bontas' => [['kulcs' => 27, 'kategoria' => 'S', 'netto' => 1000, 'afa' => 270]],
            'confidence' => ['afa_bontas' => 0.9, 'kitalált_mezo' => 0.9],
        ]);

        $this->assertNotNull($tiszta['bontas']);
        $this->assertCount(1, $tiszta['bontas']);

        // Az afa_bontas nem skalár mező, mégis lehet magabiztossága.
        $this->assertSame(0.9, $tiszta['konfidencia']['afa_bontas']);
        $this->assertArrayNotHasKey('kitalált_mezo', $tiszta['konfidencia']);
    }
}
