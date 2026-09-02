<?php

declare(strict_types=1);

namespace App\Enums;

enum Szerep: string
{
    case Tulajdonos = 'tulajdonos';
    case Szerkeszto = 'szerkeszto';
    case Megtekinto = 'megtekinto';

    public function cimke(): string
    {
        return match ($this) {
            self::Tulajdonos => 'Tulajdonos',
            self::Szerkeszto => 'Szerkesztő',
            self::Megtekinto => 'Megtekintő',
        };
    }

    /** Feltölthet, javíthat, jóváhagyhat, exportálhat. */
    public function szerkeszthet(): bool
    {
        return $this !== self::Megtekinto;
    }

    /** Számlázás, tagok kezelése, végleges törlés. */
    public function adminisztralhat(): bool
    {
        return $this === self::Tulajdonos;
    }

    /** @return array<string, string> */
    public static function opciok(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->cimke();
        }

        return $out;
    }
}
