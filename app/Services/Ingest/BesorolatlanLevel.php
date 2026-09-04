<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use RuntimeException;

/**
 * A levél megérkezett, de nem tudjuk, melyik céghez tartozik.
 *
 * Ez nem üzemzavar, hanem a leggyakoribb oka annak, hogy „nem érkezik meg a
 * számla": rossz címre ment, vagy a továbbküldés eltüntette a mi címünket a
 * fejlécekből. Külön kivétel, mert külön bánásmódot kap: figyelmeztetést a
 * naplóba a megvizsgált címekkel együtt, és külön mappát a postafiókban —
 * a feldolgozottak közé keverve pont az veszne el, amit keresni kell.
 */
final class BesorolatlanLevel extends RuntimeException
{
    /** @param  array<string, string>  $fejlecek */
    public function __construct(string $uzenet, public readonly array $fejlecek = [])
    {
        parent::__construct($uzenet);
    }
}
