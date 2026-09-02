<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\DokumentumTipus;
use App\Models\Document;

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
        'penznem' => 'Pénznem',
        'fizetesi_mod' => 'Fizetési mód',
        'konyvelendo' => 'Könyvelendő',
        'megjegyzes' => 'Megjegyzés',
        'beerkezes' => 'Beérkezés',
        'forras' => 'Forrás',
    ];

    /** Melyik oszlop szám — ezeket az xlsx számként, a csv tizedesvesszővel írja. */
    public const SZAM_OSZLOPOK = ['netto', 'afa', 'brutto'];

    /** Melyik oszlop dátum. */
    public const DATUM_OSZLOPOK = ['kelt', 'teljesites', 'fizetesi_hatarido'];

    /** @return array<string, string|float|null> */
    public static function sor(Document $d): array
    {
        $tipus = $d->doc_type;

        return [
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
