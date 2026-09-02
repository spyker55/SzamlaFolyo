<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pénzösszeg értelmezése és kiírása magyar írásmód szerint.
 *
 * A modell tizedesponttal, csoportosítás nélkül adja vissza az összeget — de az
 * ellenőrző képernyőn ember is beleír, és ő úgy gépel, ahogy a papíron látja:
 * `1 612 900,25`. Ez az osztály mindkettőt elfogadja, és `null` helyett
 * `hibas()` választ ad, ha nem érti — így a hívó vissza tud szólni a
 * felhasználónak ahelyett, hogy csendben nullát írna az adatbázisba.
 */
final class Osszeg
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $ertek,
    ) {}

    public static function ertelmez(string|int|float|null $nyers): self
    {
        if ($nyers === null) {
            return new self(true, null);
        }

        if (is_int($nyers) || is_float($nyers)) {
            return new self(true, self::kerekit((float) $nyers));
        }

        // Minden szóközfajta csoportosító jel: a sima szóköz, a nem törhető
        // szóköz (U+00A0) és a keskeny nem törhető szóköz (U+202F) is — az
        // Intl formázók maguk is ez utóbbiakat írják ki.
        $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($nyers)) ?? '';

        if ($s === '') {
            return new self(true, null);
        }

        $elojel = 1;
        if (str_starts_with($s, '-')) {
            $elojel = -1;
            $s = substr($s, 1);
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }

        // Pénznem-jelek és a magyar „Ft” lecsípése.
        $s = preg_replace('/(Ft|HUF|EUR|USD|€|\$)$/iu', '', $s) ?? $s;

        if (! preg_match('/^[0-9.,]+$/', $s)) {
            return self::hibas();
        }

        $pont = substr_count($s, '.');
        $vesszo = substr_count($s, ',');

        if ($pont > 0 && $vesszo > 0) {
            // Mindkettő szerepel: a jobbra álló a tizedesjel.
            $tizedesJel = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $csoportJel = $tizedesJel === ',' ? '.' : ',';
            $egesz = substr($s, 0, (int) strrpos($s, $tizedesJel));
            $tort = substr($s, (int) strrpos($s, $tizedesJel) + 1);

            if (! self::csoportositasRendben($egesz, $csoportJel) || ! preg_match('/^\d+$/', $tort)) {
                return self::hibas();
            }

            $egesz = str_replace($csoportJel, '', $egesz);
        } elseif ($vesszo === 1) {
            // Egyetlen vessző magyar írásmódban mindig tizedesjel.
            [$egesz, $tort] = explode(',', $s);
            if (! preg_match('/^\d+$/', $egesz) || ! preg_match('/^\d+$/', $tort)) {
                return self::hibas();
            }
        } elseif ($vesszo > 1) {
            // Több vessző csak csoportosítás lehet: 1,612,900
            if (! self::csoportositasRendben($s, ',')) {
                return self::hibas();
            }
            $egesz = str_replace(',', '', $s);
            $tort = '';
        } elseif ($pont === 1) {
            // Egyetlen pont a valóban kétes eset. A `100.000` magyarul
            // százezer, a `256.5` viszont tizedes. Csak a pontosan hármas
            // végződésű, ezres alakot vesszük csoportosításnak.
            if (preg_match('/^[1-9]\d{0,2}\.\d{3}$/', $s)) {
                $egesz = str_replace('.', '', $s);
                $tort = '';
            } else {
                [$egesz, $tort] = explode('.', $s);
                if (! preg_match('/^\d+$/', $egesz) || ! preg_match('/^\d+$/', $tort)) {
                    return self::hibas();
                }
            }
        } elseif ($pont > 1) {
            if (! self::csoportositasRendben($s, '.')) {
                return self::hibas();
            }
            $egesz = str_replace('.', '', $s);
            $tort = '';
        } else {
            $egesz = $s;
            $tort = '';
        }

        if (! preg_match('/^\d+$/', $egesz)) {
            return self::hibas();
        }

        $szam = (float) ($egesz.($tort !== '' ? '.'.$tort : ''));

        return new self(true, self::kerekit($elojel * $szam));
    }

    /** Megjelenítés: `1 612 900,25`, felesleges tizedesek nélkül. */
    public static function formaz(string|float|int|null $ertek, ?string $penznem = null): string
    {
        if ($ertek === null || $ertek === '') {
            return '—';
        }

        $szam = (float) $ertek;
        $tizedes = fmod($szam, 1.0) === 0.0 ? 0 : 2;
        $formazott = number_format($szam, $tizedes, ',', ' ');

        return $penznem !== null && $penznem !== '' ? $formazott.' '.$penznem : $formazott;
    }

    private static function hibas(): self
    {
        return new self(false, null);
    }

    /** Az oszlop `numeric(15,2)`, ezért két tizedesnél többet nem tartunk meg. */
    private static function kerekit(float $szam): string
    {
        return number_format(round($szam, 2), 2, '.', '');
    }

    /**
     * `1 234 567` alakú-e: az első csoport 1–3 jegy, a többi pontosan 3.
     * Enélkül a `12.34.567` is átcsúszna csoportosításként.
     */
    private static function csoportositasRendben(string $egesz, string $jel): bool
    {
        if (! str_contains($egesz, $jel)) {
            return (bool) preg_match('/^\d+$/', $egesz);
        }

        $reszek = explode($jel, $egesz);
        $elso = array_shift($reszek);

        if (! preg_match('/^\d{1,3}$/', (string) $elso)) {
            return false;
        }

        foreach ($reszek as $resz) {
            if (! preg_match('/^\d{3}$/', $resz)) {
                return false;
            }
        }

        return true;
    }
}
