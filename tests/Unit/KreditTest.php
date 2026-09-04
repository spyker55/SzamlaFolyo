<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Kredit;
use Tests\TestCase;

/**
 * Az oldalalapú fair-use szabály.
 *
 * Ez pénzt mozgat, ezért a határok mindkét oldalát külön állítjuk: a
 * `<=` és a `<` közti tévedés itt azt jelenti, hogy egy hétköznapi számla
 * hirtelen két kreditet fogyaszt.
 */
final class KreditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['szamlafolyo.kredit.oldal_per_kredit' => 5]);
    }

    /** A hétköznapi eset: a fair-use szabály nem érintheti a normál számlát. */
    public function test_a_normal_szamla_egy_kredit(): void
    {
        foreach ([1, 2, 3, 4, 5] as $oldal) {
            $this->assertSame(1, Kredit::oldalakbol($oldal), "{$oldal} oldal");
        }
    }

    /** A határon túl az első megkezdett oldal is új kreditet nyit. */
    public function test_a_hatar_folott_novekszik(): void
    {
        $this->assertSame(2, Kredit::oldalakbol(6));
        $this->assertSame(2, Kredit::oldalakbol(10));
        $this->assertSame(3, Kredit::oldalakbol(11));
    }

    /** A doksi példája: nyolcvan oldal nem lehet egy kredit. */
    public function test_a_nyolcvan_oldalas_koteg(): void
    {
        $this->assertSame(16, Kredit::oldalakbol(80));
    }

    /** Amiről nem tudjuk, az egy: bizonytalanságból nem számlázunk többet. */
    public function test_az_ismeretlen_oldalszam_egy_kredit(): void
    {
        $this->assertSame(1, Kredit::oldalakbol(null));
        $this->assertSame(1, Kredit::oldalakbol(0));
        $this->assertSame(1, Kredit::oldalakbol(-3));
    }

    public function test_a_hatar_atallithato(): void
    {
        config(['szamlafolyo.kredit.oldal_per_kredit' => 10]);

        $this->assertSame(1, Kredit::oldalakbol(10));
        $this->assertSame(8, Kredit::oldalakbol(80));
    }

    /** Nullás beállítás nullával osztana — a `hatar()` ezért véd. */
    public function test_a_nullas_beallitas_nem_ejti_el(): void
    {
        config(['szamlafolyo.kredit.oldal_per_kredit' => 0]);

        $this->assertSame(80, Kredit::oldalakbol(80));
    }
}
