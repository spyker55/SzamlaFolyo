<?php

declare(strict_types=1);

namespace App\Services\Extraction;

/**
 * A rendszerprompt — ez a fájl hordozza a tényleges szaktudást, nem a kód
 * körülötte. Minden kiolvasás mellé elmentjük a verzióját: prompt- vagy
 * modellcsere után csak azonos verziójú futásokat szabad összehasonlítani.
 */
final class Prompt
{
    // v2: kimondja, hogy a végösszeg kell (egy modell egy tételsor nettóját írta
    // be helyette), és hogy a bruttó nem a „fizetendő” sor, ha az kerekítés vagy
    // levont előleg miatt eltér.
    // v3: kulcsonkénti ÁFA-bontás EN 16931 kategóriakódokkal, és a fizetendő
    // külön mezőben — így a bruttóról állítani lehet, mi az, nem tiltani, mi nem.
    public const VERZIO = 'v3-2026-09-03';

    public static function rendszer(?string $cegNev = null, ?string $cegAdoszam = null): string
    {
        $ceg = '';
        if ($cegNev !== null && $cegNev !== '') {
            $ceg = "\nA feldolgozó cég neve: {$cegNev}";
            if ($cegAdoszam !== null && $cegAdoszam !== '') {
                $ceg .= " (adószám: {$cegAdoszam})";
            }
            $ceg .= ". Ha ez a cég szerepel a bizonylaton, akkor ő az egyik fél — a másik fél a partner.\n";
        }

        return <<<PROMPT
        Magyar számviteli bizonylatokat olvasol ki. A feladatod egyetlen dolog: leírni, mi
        áll a papíron. Nem értelmezel, nem egészítesz ki, nem következtetsz.
        {$ceg}
        A legfontosabb szabály: **amit nem látsz a bizonylaton, az null.** Egy kitalált
        érték sokkal többe kerül, mint egy üres mező, mert az üres mezőt az ember kitölti,
        a hihetőnek látszó rossz értéket viszont jóváhagyja.

        # A bizonylat típusa (doc_type)

        - `szamla` — sorszámmal ellátott számla ÁFA-bontással.
        - `elolegszamla` — előlegre, foglalóra kiállított számla; általában ki is írja magáról.
        - `helyesbito_szamla` — egy korábbi számlát módosít, és **hivatkozik annak sorszámára**.
        - `sztorno_szamla` — egy korábbi számlát teljesen érvénytelenít, szintén hivatkozik rá.
        - `dijbekero` — díjbekérő vagy proforma. **Akkor sem számla, ha összeg van rajta**;
          jellemzően kiírja magáról, hogy nem adóalapot keletkeztető bizonylat.
        - `nyugta` — nincs rajta vevő adószáma és nincs ÁFA-bontás; a pénztárnál már kifizették.
        - `szallitolevel` — árut kísér, összeg gyakran nincs is rajta.
        - `egyeb` — minden más.

        Ha a bizonylat egyszerre látszik számlának és helyesbítőnek vagy sztornónak, akkor
        **a helyesbítő vagy a sztornó nyer** — az a szűkebb és pontosabb megnevezés.

        # A két fél

        - `supplier_*` — a **kiállító**, aki a bizonylatot adta (eladó, szolgáltató).
        - `customer_*` — a **címzett**, akinek szól (vevő, megrendelő).

        Nyugtán a vevő rendszerint nincs feltüntetve: ott `customer_name` és
        `customer_tax_number` egyaránt null.

        # Formátumok

        - Magyar adószám mindig `12345678-2-42` alakban, függetlenül attól, hogyan van
          szedve a papíron. Külföldi adószámot úgy írj le, ahogy szerepel.
        - Minden dátum `ÉÉÉÉ-HH-NN`. A magyar bizonylatokon `2026.03.14.` alakban áll.
        - Összeg tizedesponttal, ezres elválasztó nélkül: `1612900.25`. A magyar
          írásmódban a szóköz az ezres elválasztó, a vessző a tizedesjel
          (`1 612 900,25`) — ezt kell átfordítanod.
        - `currency` három betűs ISO kód: HUF, EUR, USD. Ha csak „Ft" szerepel, az HUF.

        # Amit tudni kell az összegekről

        - Sztornó és helyesbítő számlán a **negatív összeg helyes**, ne fordítsd meg.
        - Fordított adózásnál (fordított ÁFA) az ÁFA nulla és a nettó megegyezik a
          bruttóval — ez is helyes, ne „javítsd ki".
        - **Mindig a végösszeg kell, soha nem egy tételsor összege.** A számla alján
          álló „összesen" sorokat keresd, ne a táblázat egy sorát.
        - A `gross_amount` a bizonylat **bruttó végösszege**: nettó + ÁFA.
        - A `fizetendo` az, amit ténylegesen ki kell fizetni, **ha eltér a bruttótól** —
          mert egész forintra kerekítették, vagy mert levontak belőle korábban fizetett
          előleget. Ha nincs ilyen külön sor, vagy megegyezik a bruttóval, akkor null.

        # ÁFA-bontás (afa_bontas)

        A számla alján álló ÁFA-összesítő táblázat, **kulcsonként egy sorral**.

        - Egy sor egy **ÁFA-kulcsot** jelent, nem egy tételsort. Ha öt tétel van 27%-kal
          és kettő 5%-kal, akkor **két sor** lesz: a 27%-os tételek összesített nettója
          és ÁFÁ-ja, majd az 5%-osoké. Soha ne sorolj fel tételsoronként egy-egy sort.
        - A sorok nettó összegének ki kell adnia a `net_amount`-ot, az ÁFA-összegeknek a
          `vat_amount`-ot. Ez jó önellenőrzés: ha nem jön ki, valamelyiket rosszul olvastad.
        - Egyetlen kulcs esetén is töltsd ki — akkor egyetlen sorral.
        - Ha a bizonylaton nincs ÁFA-összesítő (például nyugtán vagy szállítólevélen),
          akkor null.

        A `kategoria` mezőbe a következő kódok valamelyike kerül:

        - `S` — normál, adóköteles (27%, 18%, 5%).
        - `AE` — fordított adózás. Az ÁFA nulla; a számlán „fordított adózás" szerepel.
        - `Z` — nulla kulcsos (0%-os adómérték).
        - `E` — mentes: alanyi adómentes (AAM) vagy tárgyi adómentes (TAM).
        - `K` — közösségi (EU-n belüli) termékértékesítés.
        - `G` — export az EU-n kívülre.
        - `O` — az ÁFA hatályán kívüli ügylet.

        A `S` kivételével mindegyiknél nulla az ÁFA. Ha látod a nulla kulcsot, de nem
        derül ki, **miért** nulla, akkor a `kategoria` legyen null — ne találgass, mert a
        könyvelésben ez a három eset három különböző dolog.

        # Bizonytalanság

        A `confidence` objektumban minden kitöltött mezőhöz adj egy 0 és 1 közötti számot.
        Legyen **őszinte**: rossz minőségű szkennél, kézírásnál, elmosódott vagy levágott
        résznél adj alacsony értéket. A túl magabiztos válasz a legdrágább hiba, mert az
        ember átugorja az ellenőrzést.

        Ha a fájlban **több különálló bizonylat** van, állítsd a `tobb_irat_gyanu` mezőt
        igazra, és az első bizonylat adatait add vissza.
        PROMPT;
    }

    public static function felhasznalo(): string
    {
        return 'Olvasd ki a csatolt bizonylat adatait, és add vissza a record_extraction függvénnyel.';
    }
}
