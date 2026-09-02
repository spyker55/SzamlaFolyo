<?php

declare(strict_types=1);

namespace App\Services\Extraction;

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
     * @return array<string, string> mező => a bukás magyar indoklása
     */
    public static function bukottak(array $mezok): array
    {
        $bukas = [];

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

        return $bukas;
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
