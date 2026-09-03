<?php

declare(strict_types=1);

namespace App\Services\Extraction\Xml;

use App\Enums\DokumentumTipus;

/**
 * Az e-számlákban használt ENSZ kódlisták fordítása a mi szótárainkra.
 *
 * Mindkét formátum (CII és UBL) ugyanezeket a kódlistákat használja, ezért
 * egy helyen állnak.
 */
final class Kodok
{
    /**
     * Bizonylattípus az UNCL1001 kódlistából.
     *
     * Amit nem ismerünk fel, arra **nem tippelünk**: a null azt jelenti, hogy
     * az ellenőrző képernyőn az embernek kell kiválasztania. Egy rossz típus
     * rosszabb, mint egy üres — a díjbekérő és a számla összekeverése duplán
     * viszi be ugyanazt a költséget.
     */
    public static function bizonylattipus(?string $kod): ?string
    {
        return match ($kod) {
            '380', '389', '393', '575', '623', '780' => DokumentumTipus::Szamla->value,
            '386' => DokumentumTipus::Elolegszamla->value,
            '384', '396' => DokumentumTipus::HelyesbitoSzamla->value,
            '381' => DokumentumTipus::SztornoSzamla->value,
            '325', '326' => DokumentumTipus::Dijbekero->value,
            default => null,
        };
    }

    /**
     * Fizetési mód az UNCL4461 kódlistából, magyarul.
     *
     * Ez szabad szöveges mező nálunk, ezért az ismeretlen kódot magát adjuk
     * vissza — így legalább látszik a bizonylaton szereplő érték.
     */
    public static function fizetesiMod(?string $kod): ?string
    {
        if ($kod === null || $kod === '') {
            return null;
        }

        return match ($kod) {
            '10' => 'készpénz',
            '20' => 'csekk',
            '30', '31' => 'átutalás',
            '42' => 'bankszámlára fizetés',
            '48', '54', '55' => 'bankkártya',
            '49' => 'csoportos beszedés',
            '58' => 'SEPA átutalás',
            '59' => 'SEPA csoportos beszedés',
            '97' => 'kompenzáció',
            default => $kod,
        };
    }
}
