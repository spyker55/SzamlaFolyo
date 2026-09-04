<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\PostafiokOlvaso;
use PHPUnit\Framework\TestCase;

/**
 * Melyik mappát szabad takarítani.
 *
 * Ez a döntés azért van külön, tiszta függvényben, mert a rossz válasza
 * visszafordíthatatlan: a levél törlése után nincs mit visszaállítani.
 */
final class PostafiokTakaritasTest extends TestCase
{
    public function test_a_feldolgozott_mappa_takarithato(): void
    {
        $this->assertTrue(PostafiokOlvaso::takarithato('Feldolgozott', 'INBOX', 7));
    }

    /** A nulla nap a kikapcsolás — aki nem kér törlést, annál ne töröljünk. */
    public function test_a_nulla_nap_kikapcsolas(): void
    {
        $this->assertFalse(PostafiokOlvaso::takarithato('Feldolgozott', 'INBOX', 0));
        $this->assertFalse(PostafiokOlvaso::takarithato('Feldolgozott', 'INBOX', -1));
    }

    /**
     * A lényegi eset: a beérkező mappát soha. Ott a még fel nem dolgozott
     * levelek ülnek, és egy elgépelt `IMAP_PROCESSED_FOLDER=INBOX` különben
     * pont azokat vinné el — némán, mert a beolvasás ettől még lefutna.
     */
    public function test_a_beerkezo_mappat_soha(): void
    {
        $this->assertFalse(PostafiokOlvaso::takarithato('INBOX', 'INBOX', 7));
        $this->assertFalse(PostafiokOlvaso::takarithato('inbox', 'INBOX', 7));
        $this->assertFalse(PostafiokOlvaso::takarithato(' INBOX ', 'INBOX', 7));
    }

    public function test_az_ures_mappanev_kihagyva(): void
    {
        $this->assertFalse(PostafiokOlvaso::takarithato('', 'INBOX', 7));
        $this->assertFalse(PostafiokOlvaso::takarithato('   ', 'INBOX', 7));
    }
}
