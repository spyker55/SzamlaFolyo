<?php

declare(strict_types=1);

namespace App\Services\Extraction\Forras;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Megnézi egy beérkezett fájlról, honnan lehetne a legolcsóbban kiolvasni.
 *
 * Ez a döntés a feldolgozási lánc első lépése — és önmagában is mérés: minden
 * iratról feljegyezzük, mi *lett volna* elérhető benne. Néhány hét alatt ebből
 * derül ki, érdemes-e XML-értelmezőt írni, vagy a beérkező anyag úgyis csupa
 * szkennelt papír.
 */
final class Felderito
{
    /**
     * Ennyi karakternyi szöveg alatt nem tekintjük szövegrétegnek. Egy
     * szkennelt PDF-en is szokott lenni néhány karakter (a szkennelő szoftver
     * fejléce, oldalszám), ez pedig nem elég a kiolvasáshoz.
     */
    private const SZOVEG_KUSZOB = 200;

    /** Amit a Factur-X, a ZUGFeRD és az XRechnung a mellékletnek nevez. */
    private const XML_MELLEKLET_NEVEK = [
        'factur-x.xml',
        'zugferd-invoice.xml',
        'xrechnung.xml',
        'cii.xml',
        'ubl.xml',
    ];

    public function felderit(string $tartalom, string $mime): Eredmeny
    {
        if ($this->xmlE($tartalom, $mime)) {
            return new Eredmeny(Jelleg::StrukturaltXml, xml: $tartalom);
        }

        if ($mime !== 'application/pdf') {
            return new Eredmeny(Jelleg::Kep);
        }

        try {
            $doc = (new Parser)->parseContent($tartalom);
        } catch (Throwable $e) {
            // Egy sérült vagy szokatlan PDF-et nem dobunk el: attól még
            // elküldhető a multimodális modellnek, az elolvassa képként.
            return new Eredmeny(Jelleg::Kep, hiba: $e->getMessage());
        }

        $xml = $this->beagyazottXml($doc);

        if ($xml !== null) {
            return new Eredmeny(Jelleg::BeagyazottXml, xml: $xml, szovegHossz: $this->szovegHossz($doc));
        }

        $hossz = $this->szovegHossz($doc);

        return $hossz >= self::SZOVEG_KUSZOB
            ? new Eredmeny(Jelleg::Szovegreteg, szoveg: $this->szoveg($doc), szovegHossz: $hossz)
            : new Eredmeny(Jelleg::Kep, szovegHossz: $hossz);
    }

    /**
     * XML-e a fájl maga. A MIME-típusra nem hagyatkozunk: a levelezők és a
     * böngészők ugyanarra a fájlra hol `text/xml`-t, hol `application/xml`-t,
     * hol semmit nem mondanak.
     */
    private function xmlE(string $tartalom, string $mime): bool
    {
        if (str_contains($mime, 'xml')) {
            return true;
        }

        return str_starts_with(ltrim(substr($tartalom, 0, 200)), '<?xml');
    }

    private function beagyazottXml(object $doc): ?string
    {
        foreach ($doc->getObjectsByType('EmbeddedFile') as $objektum) {
            $tartalom = $objektum->getContent();

            if (! is_string($tartalom) || ! str_contains($tartalom, '<')) {
                continue;
            }

            // Csak azt fogadjuk el, ami tényleg XML — egy PDF-be bármit be
            // lehet ágyazni, egy csatolt kép nem adat.
            if (str_contains(substr($tartalom, 0, 200), '<?xml') || $this->ismertNev($objektum)) {
                return $tartalom;
            }
        }

        return null;
    }

    private function ismertNev(object $objektum): bool
    {
        $nev = mb_strtolower((string) $objektum->getHeader()?->get('F'));

        foreach (self::XML_MELLEKLET_NEVEK as $ismert) {
            if (str_contains($nev, $ismert)) {
                return true;
            }
        }

        return false;
    }

    private function szoveg(object $doc): string
    {
        try {
            return trim((string) $doc->getText());
        } catch (Throwable) {
            return '';
        }
    }

    private function szovegHossz(object $doc): int
    {
        return mb_strlen(preg_replace('/\s+/u', ' ', $this->szoveg($doc)) ?? '');
    }
}
