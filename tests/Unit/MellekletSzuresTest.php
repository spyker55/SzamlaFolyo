<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\PostafiokOlvaso;
use PHPUnit\Framework\TestCase;

/**
 * Melyik mellékletből lehet bizonylat.
 *
 * A szabály korábban minden `inline` mellékletet kizárt, azzal az indokkal,
 * hogy azok az aláírásban lévő logók. Ez a képekre igaz — csakhogy több
 * levelezőprogram a **PDF-et is** `inline` diszpozícióval küldi, hogy a
 * levéltörzsben megjelenítse. Így pont a számla veszett el, némán.
 */
final class MellekletSzuresTest extends TestCase
{
    public function test_az_inline_kep_alairas_logo(): void
    {
        $this->assertTrue(PostafiokOlvaso::alairasKepe('inline', 'image/png'));
        $this->assertTrue(PostafiokOlvaso::alairasKepe('INLINE', 'image/jpeg'));
    }

    /** Ez az a sor, ami miatt a számla nem érkezett meg. */
    public function test_az_inline_pdf_bizonylat(): void
    {
        $this->assertFalse(PostafiokOlvaso::alairasKepe('inline', 'application/pdf'));
    }

    /** A csatolmányként küldött kép viszont lehet fotózott nyugta. */
    public function test_a_csatolt_kep_bizonylat(): void
    {
        $this->assertFalse(PostafiokOlvaso::alairasKepe('attachment', 'image/jpeg'));
        $this->assertFalse(PostafiokOlvaso::alairasKepe(null, 'image/jpeg'));
    }
}
