<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Az adatbázisban minden idő UTC, a képernyőn minden idő budapesti. Ez az
 * egyetlen hely, ahol a kettő találkozik.
 */
final class Ido
{
    public const ZONA = 'Europe/Budapest';

    public static function most(): Carbon
    {
        return Carbon::now(self::ZONA);
    }

    public static function ma(): string
    {
        return self::most()->toDateString();
    }

    public static function datum(?CarbonInterface $ido): string
    {
        return $ido?->copy()->setTimezone(self::ZONA)->format('Y. m. d.') ?? '—';
    }

    public static function datumIdo(?CarbonInterface $ido): string
    {
        return $ido?->copy()->setTimezone(self::ZONA)->format('Y. m. d. H:i') ?? '—';
    }

    /**
     * A modell `YYYY-MM-DD`-t ad vissza, de a papíron `2026.03.14.` áll —
     * ellenőrzéskor ember is beleírhat. Ez mindkettőt elfogadja, és csak
     * valóban létező napot enged át (a `2026-02-31` nem az).
     */
    public static function datumErtelmez(?string $nyers): ?string
    {
        if ($nyers === null || trim($nyers) === '') {
            return null;
        }

        $s = trim($nyers);
        $s = preg_replace('/[.\s\/]+/', '-', $s) ?? $s;
        $s = trim($s, '-');

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return null;
        }

        [, $ev, $ho, $nap] = $m;

        if (! checkdate((int) $ho, (int) $nap, (int) $ev)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $ev, (int) $ho, (int) $nap);
    }
}
