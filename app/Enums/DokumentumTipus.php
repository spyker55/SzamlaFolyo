<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A felismerhető bizonylattípusok. Kötött szótár, nem szabad szöveg — ettől nem
 * lesz a törzsadatban egyszerre „bejövő számla” és „bjövő számla”.
 *
 * A lista szándékosan szűk és számlaközpontú: ezek között éles a különbség, és
 * ezekből lesz értelmes export a könyvelőnek.
 */
enum DokumentumTipus: string
{
    case Szamla = 'szamla';
    case Elolegszamla = 'elolegszamla';
    case HelyesbitoSzamla = 'helyesbito_szamla';
    case SztornoSzamla = 'sztorno_szamla';
    case Dijbekero = 'dijbekero';
    case Nyugta = 'nyugta';
    case Szallitolevel = 'szallitolevel';
    case Egyeb = 'egyeb';

    public function cimke(): string
    {
        return match ($this) {
            self::Szamla => 'Számla',
            self::Elolegszamla => 'Előlegszámla',
            self::HelyesbitoSzamla => 'Helyesbítő számla',
            self::SztornoSzamla => 'Sztornó számla',
            self::Dijbekero => 'Díjbekérő',
            self::Nyugta => 'Nyugta',
            self::Szallitolevel => 'Szállítólevél',
            self::Egyeb => 'Egyéb',
        };
    }

    /**
     * Számviteli bizonylat-e. A díjbekérő nem az: a rá kiállított számla
     * ugyanazt az összeget hozza, a kettő együtt duplán vinné be a költséget.
     */
    public function szamviteli(): bool
    {
        return match ($this) {
            self::Szamla, self::Elolegszamla, self::HelyesbitoSzamla,
            self::SztornoSzamla, self::Nyugta => true,
            default => false,
        };
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

    /**
     * Ismeretlen értéket nem „Egyéb”-nek mutatunk — az néma félrecímkézés
     * lenne —, hanem magát az értéket írjuk ki.
     */
    public static function cimkeje(?string $ertek): string
    {
        if ($ertek === null || $ertek === '') {
            return '—';
        }

        return self::tryFrom($ertek)?->cimke() ?? $ertek;
    }
}
