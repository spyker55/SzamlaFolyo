<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\AfaKategoria;
use App\Support\Adoszam;
use App\Support\Ido;
use App\Support\Osszeg;

/**
 * Determinisztikus ellenőrzések a kiolvasott mezőkön.
 *
 * Ezek nem javítanak és nem utasítanak el semmit — csak megmondják, melyik
 * mezőben találtak ellentmondást. A modell önbevallott magabiztossága rosszul
 * kalibrált (magabiztos akkor is, amikor téved), ezért kell mellé olyan jel,
 * ami a papírtól független: az adószám ellenőrző számjegye vagy az, hogy a
 * nettó és az ÁFA kiadja-e a bruttót.
 */
final class Validatorok
{
    /**
     * @param  array<string, mixed>  $mezok
     * @param  array<int, array<string, mixed>>|null  $bontas
     * @param  ?string  $cegAdoszam  a saját cég adószáma, ha ismert
     * @return array<string, string> mező => a bukás magyar indoklása
     */
    public static function bukottak(array $mezok, ?array $bontas = null, ?string $cegAdoszam = null): array
    {
        $bukas = self::idegenBizonylat($mezok, $cegAdoszam);

        foreach (['supplier_tax_number' => 'szállító', 'customer_tax_number' => 'vevő'] as $mezo => $ki) {
            if (Adoszam::biztosanRossz(is_string($mezok[$mezo] ?? null) ? $mezok[$mezo] : null)) {
                $bukas[$mezo] = "A {$ki} adószámának ellenőrző számjegye nem stimmel.";
            }
        }

        foreach (['issue_date' => 'A kelt', 'fulfillment_date' => 'A teljesítés dátuma', 'due_date' => 'A fizetési határidő'] as $mezo => $nev) {
            $ertek = $mezok[$mezo] ?? null;
            if ($ertek === null || $ertek === '') {
                continue;
            }

            $datum = Ido::datumErtelmez((string) $ertek);
            if ($datum === null) {
                $bukas[$mezo] = "{$nev} nem értelmezhető dátum.";

                continue;
            }

            $ev = (int) substr($datum, 0, 4);
            if ($ev < 2000 || $ev > 2100) {
                $bukas[$mezo] = "{$nev} kívül esik az értelmes tartományon.";
            }
        }

        // A határidő nem előzheti meg a keltet. Ha mégis, valamelyik dátumot
        // rosszul olvasta ki — de nem tudjuk, melyiket, ezért mindkettőt jelezzük.
        $kelt = isset($mezok['issue_date']) ? Ido::datumErtelmez((string) $mezok['issue_date']) : null;
        $hatarido = isset($mezok['due_date']) ? Ido::datumErtelmez((string) $mezok['due_date']) : null;

        if ($kelt !== null && $hatarido !== null && $hatarido < $kelt
            && ! isset($bukas['issue_date']) && ! isset($bukas['due_date'])) {
            $bukas['due_date'] = 'A fizetési határidő korábbi, mint a kelt.';
            $bukas['issue_date'] = 'A kelt későbbi, mint a fizetési határidő.';
        }

        if (isset($mezok['currency']) && is_string($mezok['currency']) && $mezok['currency'] !== ''
            && ! in_array(strtoupper($mezok['currency']), self::PENZNEMEK, true)) {
            $bukas['currency'] = 'Ismeretlen pénznemkód.';
        }

        // Nettó + ÁFA = bruttó. Az egy egységnyi tűrés a kerekítés miatt kell;
        // ennél nagyobb eltérés már kiolvasási hiba. Fordított adózásnál a
        // nulla ÁFA magától átmegy ezen.
        $netto = self::szam($mezok['net_amount'] ?? null);
        $afa = self::szam($mezok['vat_amount'] ?? null);
        $brutto = self::szam($mezok['gross_amount'] ?? null);

        if ($netto !== null && $afa !== null && $brutto !== null && abs($netto + $afa - $brutto) > 1.0) {
            $indok = 'A nettó és az ÁFA összege nem adja ki a bruttót.';
            $bukas['net_amount'] = $indok;
            $bukas['vat_amount'] = $indok;
            $bukas['gross_amount'] = $indok;
        }

        return $bukas + self::bontasBukasok($bontas, $netto, $afa);
    }

    /**
     * Az ÁFA-bontás ellenőrzései.
     *
     * A legértékesebb közülük az összegzés: ha a sorok nem adják ki a fejléc
     * végösszegét, akkor **valamelyik rossz**, és nem tudjuk, melyik — ezért a
     * fejléc mezőit is lehúzzuk. Pontosan ez fogja meg azt a hibát, amikor a
     * modell egy tételsor összegét írja be végösszegnek.
     *
     * @param  array<int, array<string, mixed>>|null  $bontas
     * @return array<string, string>
     */
    private static function bontasBukasok(?array $bontas, ?float $netto, ?float $afa): array
    {
        if ($bontas === null || $bontas === []) {
            return [];
        }

        $bukas = [];
        $osszegNetto = 0.0;
        $osszegAfa = 0.0;
        $latottKulcsok = [];

        foreach ($bontas as $sor) {
            $sorKulcs = is_numeric($sor['kulcs'] ?? null) ? (float) $sor['kulcs'] : null;
            $sorNetto = self::szam($sor['netto'] ?? null);
            $sorAfa = self::szam($sor['afa'] ?? null);
            $kategoria = is_string($sor['kategoria'] ?? null)
                ? AfaKategoria::tryFrom($sor['kategoria'])
                : null;

            $osszegNetto += $sorNetto ?? 0.0;
            $osszegAfa += $sorAfa ?? 0.0;

            if ($sorKulcs === null) {
                continue;
            }

            // Ugyanaz a kulcs kétszer: a modell tételsorokat sorolt fel
            // ahelyett, hogy kulcsonként összevonta volna.
            $azonosito = $sorKulcs.'|'.($kategoria?->value ?? '');
            if (isset($latottKulcsok[$azonosito])) {
                $bukas['afa_bontas'] = 'Ugyanaz az ÁFA-kulcs többször szerepel a bontásban.';
            }
            $latottKulcsok[$azonosito] = true;

            // A kulcsból számolt ÁFA. A tűrés a kerekítés miatt kell, de nem
            // lehet akkora, hogy két kulcs összetévesztését elfedje.
            if ($sorNetto !== null && $sorAfa !== null) {
                $varhato = $sorNetto * $sorKulcs / 100;

                if (abs($varhato - $sorAfa) > max(1.0, abs($sorNetto) * 0.005)) {
                    $bukas['afa_bontas'] = sprintf(
                        'A %s%%-os sorban a nettóból nem jön ki a feltüntetett ÁFA.',
                        rtrim(rtrim(number_format($sorKulcs, 1, ',', ''), '0'), ','),
                    );
                }
            }

            if ($kategoria !== null && $sorAfa !== null && $kategoria->nullaAfa() && abs($sorAfa) > 1.0) {
                $bukas['afa_bontas'] = sprintf(
                    '„%s" kategóriában nem lehet ÁFA.',
                    $kategoria->cimke(),
                );
            }
        }

        // Soronként külön kerekítenek, ezért soronként engedünk egy egységet.
        $tures = max(1.0, (float) count($bontas));

        foreach ([
            ['net_amount', $netto, $osszegNetto, 'a nettó'],
            ['vat_amount', $afa, $osszegAfa, 'az ÁFA'],
        ] as [$mezo, $vegosszeg, $sorokOsszege, $nev]) {
            if ($vegosszeg === null || abs($sorokOsszege - $vegosszeg) <= $tures) {
                continue;
            }

            // A szöveg mindkét helyen olvasható: a bontás alatt és a fejléc
            // mezője alatt is ez jelenik meg.
            $indok = "A bontás sorai nem adják ki {$nev} végösszeget.";
            $bukas['afa_bontas'] = $indok;
            $bukas[$mezo] = $indok;
        }

        return $bukas;
    }

    /**
     * Igazolt-e a vevő azzal, hogy az adószáma a miénk.
     *
     * Ilyenkor a vevő neve nem találgatás többé: tudjuk, kinek szól a számla.
     * Ezért a kézírás miatti plafon sem vonatkozik rá — lásd `Konfidencia`.
     *
     * Ez **csak elvenni** tud egy figyelmeztetést, hozzáadni soha. Ezért marad
     * bent akkor is, amikor a fordítottja („ez a bizonylat nem a tiéd") nem:
     * egy könyvelőirodánál, ahol a bizonylatok nem a regisztrált cégnek
     * szólnak, ez egyszerűen sosem sül el — nem zajt csinál, csak hallgat.
     *
     * @param  array<string, mixed>  $mezok
     */
    /**
     * Ez a bizonylat egyáltalán a miénk-e.
     *
     * Az egyetlen adat, amit **kívülről** ismerünk: a saját cég adószáma.
     * Minden más validátor a papírt önmagához méri. Ha a bizonylaton szerepel
     * vevő adószáma, és az sem a miénk — miközben a szállító sem mi vagyunk —,
     * akkor ez a papír nem hozzánk tartozik: rossz fájl, a szállító saját
     * példánya, vagy másnak szóló számla. Ez súlyosabb minden mezőhibánál,
     * mert nem egy mező téved, hanem az egész bizonylat.
     *
     * Három csapdát kerül ki, és mindháromra van teszt:
     *
     * - **Kimenő számlán** mi vagyunk a szállító, a vevő adószáma jogosan
     *   másé. Ezért mindkét oldalt nézzük, nem csak a vevőt.
     * - **Nyugtán** nincs vevő adószáma, az eladó meg természetesen nem mi
     *   vagyunk. Ezért csak kitöltött vevő-adószámra szólunk.
     * - **Hibás ellenőrző számjegyű** adószámra nem építünk következtetést:
     *   ilyenkor a félreolvasás a valószínűbb, nem az, hogy idegen a papír. A
     *   rossz számjegyet a saját ellenőrzése amúgy is megjelöli.
     *
     * A **törzsszámot** hasonlítjuk, nem a teljes adószámot: az ÁFA-kód és a
     * megyekód változhat, az adóalanyt az első nyolc jegy azonosítja.
     *
     * Ha a cégnek nincs adószáma megadva, ez az ellenőrzés néma. Ez egyben a
     * kikapcsolója is: aki több ügyfél iratát egyetlen cégben kezeli, annak
     * üresen kell hagynia az adószámot — különben minden bizonylata piros
     * lenne. Egy validátor, ami jogos munkamenetre tüzel, rosszabb a semminél:
     * két hét alatt mindenki átlép a piroson, és viszi magával a többi jelzés
     * súlyát is.
     *
     * @param  array<string, mixed>  $mezok
     * @return array<string, string>
     */
    private static function idegenBizonylat(array $mezok, ?string $cegAdoszam): array
    {
        $vevoNyers = is_string($mezok['customer_tax_number'] ?? null) ? $mezok['customer_tax_number'] : null;
        $szallitoNyers = is_string($mezok['supplier_tax_number'] ?? null) ? $mezok['supplier_tax_number'] : null;

        $mienk = Adoszam::torzsszam($cegAdoszam);
        $vevo = Adoszam::torzsszam($vevoNyers);

        if ($mienk === null || $vevo === null) {
            return [];
        }

        if (Adoszam::biztosanRossz($vevoNyers) || Adoszam::biztosanRossz($szallitoNyers)) {
            return [];
        }

        if ($vevo === $mienk || Adoszam::torzsszam($szallitoNyers) === $mienk) {
            return [];
        }

        $indok = 'Ez a bizonylat nem a te cégednek szól: sem a vevő, sem a szállító adószáma nem a tiéd.';

        return ['customer_tax_number' => $indok, 'supplier_tax_number' => $indok];
    }

    public static function vevoIgazolt(array $mezok, ?string $cegAdoszam): bool
    {
        $vevoNyers = is_string($mezok['customer_tax_number'] ?? null) ? $mezok['customer_tax_number'] : null;

        // Hibás ellenőrző számjegyű adószámra nem építünk következtetést —
        // sem terhelőt, sem mentesítőt.
        if (Adoszam::biztosanRossz($vevoNyers)) {
            return false;
        }

        $mienk = Adoszam::torzsszam($cegAdoszam);

        return $mienk !== null && Adoszam::torzsszam($vevoNyers) === $mienk;
    }

    private const PENZNEMEK = [
        'HUF', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'PLN', 'RON', 'SEK', 'DKK', 'NOK', 'HRK', 'RSD', 'UAH', 'JPY', 'CNY',
    ];

    private static function szam(mixed $ertek): ?float
    {
        if ($ertek === null || $ertek === '') {
            return null;
        }

        if (is_int($ertek) || is_float($ertek)) {
            return (float) $ertek;
        }

        $eredmeny = Osszeg::ertelmez((string) $ertek);

        return $eredmeny->ok && $eredmeny->ertek !== null ? (float) $eredmeny->ertek : null;
    }
}
