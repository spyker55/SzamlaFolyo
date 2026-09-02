<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\DokumentumTipus;

/**
 * A kiolvasás kötött alakja. Ez egyszerre a modellnek adott JSON Schema és a
 * visszajövő válasz ellenőrzési szabálya — egy helyen áll, hogy a kettő ne
 * tudjon szétcsúszni.
 */
final class Sema
{
    /** A mezők, amiket a modell kitölt. */
    public const MEZOK = [
        'doc_type',
        'supplier_name',
        'supplier_tax_number',
        'customer_name',
        'customer_tax_number',
        'doc_number',
        'issue_date',
        'fulfillment_date',
        'due_date',
        'payment_method',
        'currency',
        'net_amount',
        'vat_amount',
        'gross_amount',
    ];

    public const FUGGVENY_NEV = 'record_extraction';

    /** Magyar cimkék a felülethez. Az export fejlécei külön állnak (Export\Oszlopok). */
    public const CIMKEK = [
        'doc_type' => 'Bizonylat típusa',
        'supplier_name' => 'Szállító',
        'supplier_tax_number' => 'Szállító adószáma',
        'customer_name' => 'Vevő',
        'customer_tax_number' => 'Vevő adószáma',
        'doc_number' => 'Bizonylatszám',
        'issue_date' => 'Kelt',
        'fulfillment_date' => 'Teljesítés',
        'due_date' => 'Fizetési határidő',
        'payment_method' => 'Fizetési mód',
        'currency' => 'Pénznem',
        'net_amount' => 'Nettó',
        'vat_amount' => 'ÁFA',
        'gross_amount' => 'Bruttó',
    ];

    public static function toolSema(): array
    {
        $szoveg = static fn (string $leiras): array => [
            'type' => ['string', 'null'],
            'description' => $leiras,
        ];

        $szam = static fn (string $leiras): array => [
            'type' => ['number', 'null'],
            'description' => $leiras,
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'doc_type' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(array_column(DokumentumTipus::cases(), 'value'), [null]),
                    'description' => 'A bizonylat típusa a megadott listából.',
                ],
                'supplier_name' => $szoveg('A kiállító (eladó, szolgáltató) neve, ahogy a bizonylaton szerepel.'),
                'supplier_tax_number' => $szoveg('A kiállító adószáma. Magyar adószám 12345678-2-42 alakban.'),
                'customer_name' => $szoveg('A vevő (címzett) neve. Nyugtán jellemzően nincs ilyen.'),
                'customer_tax_number' => $szoveg('A vevő adószáma, ha szerepel a bizonylaton.'),
                'doc_number' => $szoveg('A bizonylat saját sorszáma (számlaszám).'),
                'issue_date' => $szoveg('A kiállítás kelte, ÉÉÉÉ-HH-NN alakban.'),
                'fulfillment_date' => $szoveg('A teljesítés dátuma, ÉÉÉÉ-HH-NN alakban.'),
                'due_date' => $szoveg('A fizetési határidő, ÉÉÉÉ-HH-NN alakban.'),
                'payment_method' => $szoveg('Fizetési mód, ahogy a bizonylaton áll (átutalás, készpénz, bankkártya).'),
                'currency' => [
                    'type' => ['string', 'null'],
                    'description' => 'Három betűs ISO pénznemkód. A „Ft" HUF.',
                ],
                'net_amount' => $szam('Nettó végösszeg tizedesponttal, csoportosítás nélkül.'),
                'vat_amount' => $szam('ÁFA végösszeg. Fordított adózásnál 0.'),
                'gross_amount' => $szam('Bruttó végösszeg (fizetendő).'),
                'tobb_irat_gyanu' => [
                    'type' => 'boolean',
                    'description' => 'Igaz, ha a fájlban több különálló bizonylat van.',
                ],
                'confidence' => [
                    'type' => 'object',
                    'description' => 'Mezőnkénti magabiztosság 0 és 1 között. Rossz szkennél legyen alacsony.',
                    'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                ],
            ],
            'required' => ['tobb_irat_gyanu', 'confidence'],
        ];
    }

    /**
     * A modell válaszának megtisztítása: ismeretlen mező kiesik, a típusok a
     * helyükre kerülnek. Amit nem értünk, azt nem írjuk be — a null itt
     * mindig biztonságosabb, mint a találgatás.
     *
     * @return array{mezok: array<string, mixed>, tobb_irat_gyanu: bool, konfidencia: array<string, float>}
     */
    public static function tisztit(array $nyers): array
    {
        $mezok = [];

        foreach (self::MEZOK as $mezo) {
            $ertek = $nyers[$mezo] ?? null;

            if (is_string($ertek)) {
                $ertek = trim($ertek);
                if ($ertek === '' || strcasecmp($ertek, 'null') === 0) {
                    $ertek = null;
                }
            }

            $mezok[$mezo] = $ertek;
        }

        // A típus kötött szótárból jön; amit nem ismerünk fel, azt eldobjuk,
        // különben érvénytelen érték kerülne az adatbázisba.
        if (is_string($mezok['doc_type'])) {
            $mezok['doc_type'] = DokumentumTipus::tryFrom($mezok['doc_type'])?->value;
        } else {
            $mezok['doc_type'] = null;
        }

        $konfidencia = [];
        foreach ((array) ($nyers['confidence'] ?? []) as $mezo => $ertek) {
            if (in_array($mezo, self::MEZOK, true) && is_numeric($ertek)) {
                $konfidencia[$mezo] = max(0.0, min(1.0, (float) $ertek));
            }
        }

        return [
            'mezok' => $mezok,
            'tobb_irat_gyanu' => (bool) ($nyers['tobb_irat_gyanu'] ?? false),
            'konfidencia' => $konfidencia,
        ];
    }
}
