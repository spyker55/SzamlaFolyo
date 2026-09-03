<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Szabályos, minimális PDF-ek építése tesztekhez — helyes xref-táblával, hogy
 * valódi értelmezők is elfogadják őket.
 *
 * Azért kézzel, mert a felderítés viselkedését pont a fájl szerkezete dönti el
 * (van-e szövegréteg, van-e beágyazott melléklet, tömörített-e), és ezt
 * determinisztikusan kell tudnunk előállítani.
 */
final class PdfFixtura
{
    private const SZOVEG = [
        'SZAMLA  DV-2025/1170',
        'Szallito: Dinavill Kft.  Adoszam: 26606541-2-42',
        'Vevo: Centervill Kft.  Adoszam: 11176165-2-10',
        'Kelt: 2025.03.06.  Fizetesi hatarido: 2025.03.06.',
        'Netto osszesen: 4 016,28   AFA 27%: 1 084,40   Brutto: 5 100,68',
        'Fizetendo: 5 101 Ft   Fizetesi mod: Online bankkartya',
    ];

    /** Szövegréteges PDF — a szöveg kiolvasható belőle kép nélkül. */
    public static function szovegreteggel(): string
    {
        return self::epit(self::alapObjektumok());
    }

    /** Factur-X: ugyanaz, de mellékletként XML-t hordoz. */
    public static function beagyazottXmlLel(string $xml, bool $tomoritve = false): string
    {
        $objektumok = self::alapObjektumok();
        $objektumok[0] = '<< /Type /Catalog /Pages 2 0 R'
            .' /Names << /EmbeddedFiles << /Names [(factur-x.xml) 6 0 R] >> >> /AF [6 0 R] >>';
        $objektumok[] = '<< /Type /Filespec /F (factur-x.xml) /UF (factur-x.xml)'
            .' /AFRelationship /Alternative /EF << /F 7 0 R >> >>';
        $objektumok[] = $tomoritve
            ? self::folyam('/Type /EmbeddedFile /Subtype /text#2Fxml /Filter /FlateDecode', (string) gzcompress($xml))
            : self::folyam('/Type /EmbeddedFile /Subtype /text#2Fxml', $xml);

        return self::epit($objektumok);
    }

    /** Szkennelt papír: oldal van, kiolvasható szöveg nincs. */
    public static function szovegNelkul(): string
    {
        $objektumok = self::alapObjektumok();
        $objektumok[3] = self::folyam('', 'BT /F1 11 Tf 40 800 Td (2/1) Tj ET');

        return self::epit($objektumok);
    }

    /** @return array<int, string> */
    private static function alapObjektumok(): array
    {
        $tartalom = "BT /F1 11 Tf 40 800 Td 14 TL\n";
        foreach (self::SZOVEG as $sor) {
            $tartalom .= '('.$sor.") Tj T*\n";
        }
        $tartalom .= 'ET';

        return [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R'
                .' /Resources << /Font << /F1 5 0 R >> >> >>',
            self::folyam('', $tartalom),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
    }

    private static function folyam(string $szotar, string $tartalom): string
    {
        return '<< '.$szotar.' /Length '.strlen($tartalom)." >>\nstream\n".$tartalom."\nendstream";
    }

    /** @param  array<int, string>  $objektumok */
    private static function epit(array $objektumok): string
    {
        $ki = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsetek = [];

        foreach ($objektumok as $i => $test) {
            $offsetek[] = strlen($ki);
            $ki .= ($i + 1)." 0 obj\n".$test."\nendobj\n";
        }

        $xrefOffset = strlen($ki);
        $db = count($objektumok) + 1;
        $ki .= "xref\n0 {$db}\n0000000000 65535 f \n";

        foreach ($offsetek as $offset) {
            $ki .= sprintf("%010d 00000 n \n", $offset);
        }

        return $ki."trailer\n<< /Size {$db} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
