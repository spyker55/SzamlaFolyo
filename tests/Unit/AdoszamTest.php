<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Adoszam;
use PHPUnit\Framework\TestCase;

final class AdoszamTest extends TestCase
{
    /** Valódi, nyilvános adószámok — az algoritmust ezeken kötjük le. */
    public function test_valodi_adoszamokat_elfogad(): void
    {
        $this->assertTrue(Adoszam::ervenyes('10773381-2-44'));
        $this->assertTrue(Adoszam::ervenyes('10537914-4-44'));
        $this->assertTrue(Adoszam::ervenyes('10773381'));
    }

    public function test_elrontott_ellenorzo_szamjegyet_elutasit(): void
    {
        $this->assertFalse(Adoszam::ervenyes('10773382-2-44'));
        $this->assertFalse(Adoszam::ervenyes('12345678-2-42'));
    }

    public function test_ervenytelen_afa_kodot_elutasit(): void
    {
        // Ugyanaz a törzsszám, de a 9. jegy nem lehet 0 vagy 6.
        $this->assertFalse(Adoszam::ervenyes('10773381-0-44'));
        $this->assertFalse(Adoszam::ervenyes('10773381-6-44'));
    }

    public function test_formazas_es_torzsszam(): void
    {
        $this->assertSame('10773381-2-44', Adoszam::formaz('107733812 44'));
        $this->assertSame('10773381', Adoszam::torzsszam('10773381-2-44'));
        $this->assertNull(Adoszam::formaz('   '));
    }

    /**
     * Külföldi vagy EU-s azonosító nem magyar alakú — egy téves validátor
     * soha ne álljon egy valódi partner útjába.
     */
    public function test_kulfoldi_azonositot_nem_minosit_rossznak(): void
    {
        $this->assertFalse(Adoszam::biztosanRossz('ATU12345678'));
        $this->assertFalse(Adoszam::biztosanRossz('DE 811 122 233'));
        $this->assertFalse(Adoszam::biztosanRossz(null));
        $this->assertFalse(Adoszam::biztosanRossz(''));
    }

    public function test_magyar_alaku_rossz_szamot_megjelol(): void
    {
        $this->assertTrue(Adoszam::biztosanRossz('12345678-2-42'));
        $this->assertFalse(Adoszam::biztosanRossz('10773381-2-44'));
    }
}
