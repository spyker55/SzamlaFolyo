<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ido;
use PHPUnit\Framework\TestCase;

final class IdoTest extends TestCase
{
    public function test_elfogadja_a_ketfele_datumirast(): void
    {
        $this->assertSame('2026-03-14', Ido::datumErtelmez('2026-03-14'));
        $this->assertSame('2026-03-14', Ido::datumErtelmez('2026.03.14.'));
        $this->assertSame('2026-03-14', Ido::datumErtelmez('2026. 03. 14.'));
        $this->assertSame('2026-03-04', Ido::datumErtelmez('2026.3.4'));
        $this->assertSame('2026-03-14', Ido::datumErtelmez('2026/03/14'));
    }

    /** A nem létező nap nem dátum — a `2026-02-31` némán március 3-ává válna. */
    public function test_nem_letezo_napot_elutasit(): void
    {
        $this->assertNull(Ido::datumErtelmez('2026-02-31'));
        $this->assertNull(Ido::datumErtelmez('2026-13-01'));
        $this->assertNull(Ido::datumErtelmez('nincs dátum'));
        $this->assertNull(Ido::datumErtelmez(''));
        $this->assertNull(Ido::datumErtelmez(null));
    }
}
