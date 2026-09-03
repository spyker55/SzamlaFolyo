<?php

declare(strict_types=1);

namespace App\Services\Extraction\Xml;

use DOMDocument;
use DOMXPath;
use Throwable;

/**
 * A feldolgozási lánc legolcsóbb foka: ha az adat strukturáltan is megvan,
 * modellhívás nélkül olvassuk ki. Nulla forint, nulla találgatás.
 *
 * # Biztonság
 *
 * Az itt érkező XML **nem megbízható**: e-mailben bárki küldhet ilyet a cég
 * beküldési címére, hitelesítés nélkül. Ezért:
 *
 * - a betöltés nem kap `LIBXML_NOENT`-et, tehát az entitásokat nem
 *   helyettesítjük be — enélkül egy `SYSTEM "file:///etc/passwd"` entitás
 *   kiolvashatná a szerver fájljait (XXE);
 * - a `LIBXML_NONET` megtiltja a hálózati elérést, tehát külső DTD-t vagy
 *   entitást akkor sem tölt be, ha a PHP beállítás megengedné;
 * - a méret felülről korlátozott, mert a beágyazott XML tömörítve érkezik, és
 *   egy kicsi PDF-melléklet kicsomagolva is elszabadulhat.
 */
final class XmlKiolvaso
{
    /**
     * Ennél nagyobb XML-t nem értelmezünk. Egy e-számla néhány tíz kilobájt;
     * a nagyságrendekkel nagyobb fájl vagy támadás, vagy nem e-számla.
     */
    public const MAX_BAJT = 4 * 1024 * 1024;

    /** @var array<int, Ertelmezo> */
    private readonly array $ertelmezok;

    public function __construct()
    {
        $this->ertelmezok = [new CiiErtelmezo, new UblErtelmezo];
    }

    /**
     * Kiolvasás strukturált XML-ből.
     *
     * A null azt jelenti: ezt nem tudjuk értelmezni — menjen a modellhez.
     * Ez nem hiba, hanem a lánc következő foka.
     *
     * @return array{nev: string, nyers: array<string, mixed>}|null
     */
    public function ertelmez(string $xml): ?array
    {
        if ($xml === '' || strlen($xml) > self::MAX_BAJT) {
            return null;
        }

        $doc = $this->betolt($xml);

        if ($doc === null) {
            return null;
        }

        foreach ($this->ertelmezok as $ertelmezo) {
            if (! $ertelmezo->tamogatja($doc)) {
                continue;
            }

            try {
                return [
                    'nev' => $ertelmezo->nev(),
                    'nyers' => $ertelmezo->ertelmez(new DOMXPath($doc)),
                ];
            } catch (Throwable $e) {
                // Egy szokatlan szerkezetű fájl nem állíthatja meg a
                // feldolgozást: ilyenkor a modell még mindig elolvassa.
                report($e);

                return null;
            }
        }

        return null;
    }

    /** A biztonságos betöltés — lásd az osztály fejlécét. */
    private function betolt(string $xml): ?DOMDocument
    {
        $doc = new DOMDocument;

        $elozoHibakezeles = libxml_use_internal_errors(true);

        try {
            $sikerult = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable) {
            $sikerult = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($elozoHibakezeles);
        }

        // A doctype-ot tartalmazó fájlt eldobjuk. Entitást nem helyettesítünk
        // be, tehát önmagában nem lenne veszélyes — de egy e-számlában nincs
        // helye, és így az entitás-alapú támadások egész osztálya kiesik.
        if ($sikerult === false || $doc->documentElement === null || $doc->doctype !== null) {
            return null;
        }

        return $doc;
    }
}
