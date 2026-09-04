<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\DokumentumTipus;
use App\Models\Document;
use App\Support\AfaBontas;

/**
 * Az export egyetlen igazsága: mi a fejléc, és mi kerül a cellába.
 *
 * Mindhárom formátum (xlsx, csv, json) ugyanezt olvassa, hogy ne fordulhasson
 * elő, hogy a könyvelő más számot lát az Excelben, mint a JSON-ban.
 */
final class Oszlopok
{
    /** kulcs => magyar fejléc. A kulcs a JSON mezőneve is. */
    public const FEJLECEK = [
        'tipus' => 'Típus',
        'szallito' => 'Szállító',
        'szallito_adoszam' => 'Szállító adószáma',
        'vevo' => 'Vevő',
        'vevo_adoszam' => 'Vevő adószáma',
        'bizonylatszam' => 'Bizonylatszám',
        'kelt' => 'Kelt',
        'teljesites' => 'Teljesítés',
        'fizetesi_hatarido' => 'Fizetési határidő',
        'netto' => 'Nettó',
        'afa' => 'ÁFA',
        'brutto' => 'Bruttó',
        'fizetendo' => 'Fizetendő',
        'netto_27' => 'Nettó 27',
        'afa_27' => 'ÁFA 27',
        'netto_18' => 'Nettó 18',
        'afa_18' => 'ÁFA 18',
        'netto_5' => 'Nettó 5',
        'afa_5' => 'ÁFA 5',
        'netto_0' => 'Nettó 0',
        'netto_egyeb' => 'Nettó egyéb',
        'afa_egyeb' => 'ÁFA egyéb',
        'penznem' => 'Pénznem',
        'fizetesi_mod' => 'Fizetési mód',
        'konyvelendo' => 'Könyvelendő',
        'megjegyzes' => 'Megjegyzés',
        'beerkezes' => 'Beérkezés',
        'forras' => 'Forrás',
    ];

    /**
     * Melyik oszlop szám — ezeket az xlsx számként, a csv tizedesvesszővel írja.
     * A kulcsonkénti oszlopok is ide tartoznak: az az egész értelmük, hogy a
     * könyvelő össze tudja adni őket az Excelben.
     */
    public const SZAM_OSZLOPOK = ['netto', 'afa', 'brutto', 'fizetendo', ...AfaBontas::OSZLOPOK];

    /** Melyik oszlop dátum. */
    public const DATUM_OSZLOPOK = ['kelt', 'teljesites', 'fizetesi_hatarido'];

    /**
     * Egy bizonylat exportsora. A visszaadott tömb az `afa_bontas` kulcson a
     * bontás beágyazott alakját is viszi — a FEJLECEK-ben nincs benne, ezért a
     * csv és az xlsx nem látja, egyedül a JSON írja ki.
     *
     * @return array<string, string|float|array<int, array<string, mixed>>|null>
     */
    public static function sor(Document $d): array
    {
        $tipus = $d->doc_type;

        // A kulcsonkénti oszlopok a bontásból számolódnak, nem külön tárolt
        // adatból — így nem tudnak elcsúszni attól, amit a képernyő mutat. A
        // sorrend itt közömbös: az írók a FEJLECEK kulcsain mennek végig.
        $vodrok = AfaBontas::vodrok($d->afa_bontas);

        return $vodrok + [
            'tipus' => DokumentumTipus::cimkeje($tipus?->value),
            'szallito' => $d->supplier_name,
            'szallito_adoszam' => $d->supplier_tax_number,
            'vevo' => $d->customer_name,
            'vevo_adoszam' => $d->customer_tax_number,
            'bizonylatszam' => $d->doc_number,
            'kelt' => $d->issue_date?->format('Y-m-d'),
            'teljesites' => $d->fulfillment_date?->format('Y-m-d'),
            'fizetesi_hatarido' => $d->due_date?->format('Y-m-d'),
            'netto' => self::szam($d->net_amount),
            'afa' => self::szam($d->vat_amount),
            'brutto' => self::szam($d->gross_amount),
            'fizetendo' => self::szam($d->fizetendo),
            'penznem' => $d->currency,
            'fizetesi_mod' => $d->payment_method,
            // A díjbekérő és a rá kiállított számla együtt kétszer vinné be
            // ugyanazt a költséget, ezért az összesítés csak a számviteli
            // bizonylatokra megy — és ez az oszlop ugyanarra a kérdésre felel,
            // hogy az Excelben rászűrve ugyanaz jöjjön ki.
            'konyvelendo' => $tipus?->szamviteli() ? 'igen' : 'nem',
            'megjegyzes' => $d->note,
            'beerkezes' => $d->created_at?->format('Y-m-d'),
            'forras' => $d->source === 'email' ? 'e-mail' : 'feltöltés',
            'afa_bontas' => $d->afa_bontas ?: null,
        ];
    }

    /**
     * Pénznemenkénti összesítés a könyvelendő sorokra. Pénznemek soha nem
     * adódnak össze — egy 100 EUR és egy 100 HUF nem 200 semmi.
     *
     * @param  iterable<Document>  $dokumentumok
     * @return array<string, array{netto: float, afa: float, brutto: float, darab: int}>
     */
    public static function osszesites(iterable $dokumentumok): array
    {
        $osszeg = [];

        foreach ($dokumentumok as $d) {
            if (! $d->doc_type?->szamviteli()) {
                continue;
            }

            $penznem = $d->currency ?: '—';
            $osszeg[$penznem] ??= ['netto' => 0.0, 'afa' => 0.0, 'brutto' => 0.0, 'darab' => 0];
            $osszeg[$penznem]['netto'] += (float) $d->net_amount;
            $osszeg[$penznem]['afa'] += (float) $d->vat_amount;
            $osszeg[$penznem]['brutto'] += (float) $d->gross_amount;
            $osszeg[$penznem]['darab']++;
        }

        // A lebegőpontos összeadás sodródását itt egyszer visszavágjuk.
        foreach ($osszeg as $penznem => $ertekek) {
            foreach (['netto', 'afa', 'brutto'] as $mezo) {
                $osszeg[$penznem][$mezo] = round($ertekek[$mezo], 2);
            }
        }

        ksort($osszeg);

        return $osszeg;
    }

    private static function szam(mixed $ertek): ?float
    {
        return $ertek === null ? null : (float) $ertek;
    }
}
