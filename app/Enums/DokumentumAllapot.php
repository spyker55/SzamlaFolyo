<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A dokumentum útja a rendszerben.
 *
 *   feltoltve → feldolgozas_alatt → ellenorzesre_var → jovahagyva → exportalva
 *
 * Mellékágak: `hiba` (a kiolvasás háromszor elszállt) és `duplikatum` (ugyanaz
 * a fájl már bent van). Egyik sem tűnik el csendben, mindkettő látszik a
 * Beérkezőben.
 */
enum DokumentumAllapot: string
{
    case Feltoltve = 'feltoltve';
    case FeldolgozasAlatt = 'feldolgozas_alatt';
    case EllenorzesreVar = 'ellenorzesre_var';
    case Jovahagyva = 'jovahagyva';
    case Exportalva = 'exportalva';
    case Hiba = 'hiba';
    case Duplikatum = 'duplikatum';

    public function cimke(): string
    {
        return match ($this) {
            self::Feltoltve => 'Sorban áll',
            self::FeldolgozasAlatt => 'Feldolgozás alatt',
            self::EllenorzesreVar => 'Ellenőrzésre vár',
            self::Jovahagyva => 'Jóváhagyva',
            self::Exportalva => 'Exportálva',
            self::Hiba => 'Hiba',
            self::Duplikatum => 'Duplikátum',
        };
    }

    /**
     * Amiért a menü jelez: az irat **emberre vár**.
     *
     * Az ellenőrzésre váró és a hibás iratról is a felhasználónak kell
     * döntenie. A sorban állótól és a feldolgozás alattitól nincs mit kérni —
     * azok maguktól továbbmennek, és ha bejelentkeznének a jelzőbe, a szám
     * folyton mozogna anélkül, hogy bárkinek dolga lenne vele.
     */
    public static function emberreVarErtekek(): array
    {
        return [
            self::EllenorzesreVar->value,
            self::Hiba->value,
        ];
    }

    /** A Beérkezőben látszó, még nem kész állapotok. */
    public static function beerkezoErtekek(): array
    {
        return [
            self::Feltoltve->value,
            self::FeldolgozasAlatt->value,
            self::EllenorzesreVar->value,
            self::Hiba->value,
            self::Duplikatum->value,
        ];
    }
}
