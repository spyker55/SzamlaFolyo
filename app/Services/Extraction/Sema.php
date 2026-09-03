<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\AfaKategoria;
use App\Enums\DokumentumTipus;

/**
 * A kiolvasás kötött alakja. Ez egyszerre a modellnek adott JSON Schema és a
 * visszajövő válasz ellenőrzési szabálya — egy helyen áll, hogy a kettő ne
 * tudjon szétcsúszni.
 */
final class Sema
{
    /**
     * A skalár mezők, amiket a modell kitölt — és amiket az ember egy-egy
     * beviteli mezőben javít. Az ÁFA-bontás szándékosan **nincs** köztük: az
     * ismétlődő sorok halmaza, és minden itteni ciklus skalárt feltételez.
     */
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
        'fizetendo',
    ];

    /** Amit összegként kell értelmezni és formázni. */
    public const OSSZEG_MEZOK = ['net_amount', 'vat_amount', 'gross_amount', 'fizetendo'];

    /** Amit dátumként kell értelmezni és formázni. */
    public const DATUM_MEZOK = ['issue_date', 'fulfillment_date', 'due_date'];

    /**
     * Ennyi bontássornál többet nem fogadunk el. Egy bizonylaton legfeljebb
     * néhány ÁFA-kulcs van; ennél hosszabb lista azt jelenti, hogy a modell
     * tételsorokat sorolt fel — azt nem tároljuk el a json oszlopban.
     */
    public const BONTAS_MAX_SOR = 12;

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
        'fizetendo' => 'Fizetendő',
        'afa_bontas' => 'ÁFA-bontás',
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
                'gross_amount' => $szam('Bruttó végösszeg: nettó + ÁFA.'),
                'fizetendo' => $szam('A ténylegesen fizetendő összeg, ha eltér a bruttótól (kerekítés vagy levont előleg miatt). Ha nem tér el, null.'),
                'afa_bontas' => [
                    'type' => ['array', 'null'],
                    'description' => 'ÁFA-kulcsonként egy sor, a tételsorokat kulcsonként összevonva. Soha nem tételsoronként egy sor.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'kulcs' => [
                                'type' => ['number', 'null'],
                                'description' => 'Az ÁFA-kulcs százalékban: 27, 18, 5 vagy 0.',
                            ],
                            'kategoria' => [
                                'type' => ['string', 'null'],
                                'enum' => array_merge(array_column(AfaKategoria::cases(), 'value'), [null]),
                                'description' => 'Az ÁFA-kategória kódja a megadott listából.',
                            ],
                            'netto' => $szam('Az ehhez a kulcshoz tartozó adóalap (nettó) összesen.'),
                            'afa' => $szam('Az ehhez a kulcshoz tartozó ÁFA összesen.'),
                        ],
                        'required' => ['kulcs', 'kategoria', 'netto', 'afa'],
                    ],
                ],
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
     * @return array{mezok: array<string, mixed>, bontas: array<int, array<string, mixed>>|null, tobb_irat_gyanu: bool, konfidencia: array<string, float>}
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
            $ismert = in_array($mezo, self::MEZOK, true) || $mezo === 'afa_bontas';

            if ($ismert && is_numeric($ertek)) {
                $konfidencia[$mezo] = max(0.0, min(1.0, (float) $ertek));
            }
        }

        return [
            'mezok' => $mezok,
            'bontas' => self::tisztitBontas($nyers['afa_bontas'] ?? null),
            'tobb_irat_gyanu' => (bool) ($nyers['tobb_irat_gyanu'] ?? false),
            'konfidencia' => $konfidencia,
        ];
    }

    /**
     * Az ÁFA-bontás alakra hozása: ismeretlen kulcsok kiesnek, a kategória a
     * kötött szótárból jön. Az **értékek** értelmezése (kulcs számmá, összegek
     * a mi alakunkra) nem itt történik, hanem a Kiolvasóban — ugyanott, ahol a
     * többi összegé és dátumé.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function tisztitBontas(mixed $nyers): ?array
    {
        if (! is_array($nyers) || $nyers === []) {
            return null;
        }

        $sorok = [];

        foreach ($nyers as $sor) {
            if (count($sorok) >= self::BONTAS_MAX_SOR) {
                break;
            }

            if (! is_array($sor)) {
                continue;
            }

            $kulcs = $sor['kulcs'] ?? null;
            $netto = $sor['netto'] ?? null;

            // Kulcs vagy adóalap nélkül a sor semmire nem használható: se
            // könyvelni, se ellenőrizni nem lehet. Az üres sor kiesik.
            if (self::ures($kulcs) || self::ures($netto)) {
                continue;
            }

            $kategoria = $sor['kategoria'] ?? null;

            $sorok[] = [
                'kulcs' => $kulcs,
                'kategoria' => is_string($kategoria) ? AfaKategoria::tryFrom($kategoria)?->value : null,
                'netto' => $netto,
                'afa' => self::ures($sor['afa'] ?? null) ? null : $sor['afa'],
            ];
        }

        return $sorok === [] ? null : $sorok;
    }

    private static function ures(mixed $ertek): bool
    {
        return $ertek === null || (is_string($ertek) && trim($ertek) === '');
    }
}
