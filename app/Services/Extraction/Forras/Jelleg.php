<?php

declare(strict_types=1);

namespace App\Services\Extraction\Forras;

/**
 * Milyen forrásból lehetne kiolvasni ezt a fájlt — a legolcsóbbtól a
 * legdrágábbig.
 *
 * A sorrend maga a feldolgozási lánc: strukturált adat → szövegréteg →
 * multimodális modell → ember. Ami feljebb van, az olcsóbb és pontosabb;
 * drága modellbe csak az menjen, amit másképp nem lehet elolvasni.
 */
enum Jelleg: string
{
    /** Önálló XML e-számla: nincs mit kiolvasni, az adat készen van. */
    case StrukturaltXml = 'strukturalt_xml';

    /** PDF, ami mellékletként XML-t hordoz (Factur-X, ZUGFeRD, XRechnung). */
    case BeagyazottXml = 'beagyazott_xml';

    /** PDF szövegréteggel: a szöveg kiolvasható, kép nélkül is. */
    case Szovegreteg = 'szovegreteg';

    /** Szkennelt vagy fotózott: csak a képpont marad. */
    case Kep = 'kep';

    public function cimke(): string
    {
        return match ($this) {
            self::StrukturaltXml => 'Strukturált XML',
            self::BeagyazottXml => 'PDF beágyazott XML-lel',
            self::Szovegreteg => 'PDF szövegréteggel',
            self::Kep => 'Kép vagy szkennelt PDF',
        };
    }

    /** Kell-e egyáltalán modellhívás — vagy az adat készen áll. */
    public function igenyelModellt(): bool
    {
        return match ($this) {
            self::StrukturaltXml, self::BeagyazottXml => false,
            default => true,
        };
    }
}
