<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Visszajelzés a felhasználónak azokon a képernyőkön, ahol a művelet után
 * **nem** történik navigáció.
 *
 * A `session()->flash()` ilyenkor nem jó: a Livewire-frissítés csak a
 * komponenst rajzolja újra, az elrendezést nem — a flash üzenet pedig ott
 * jelenne meg, így a felhasználó semmit nem látna. (Ahol viszont átirányítunk,
 * ott a flash a helyes eszköz, mert a teljes oldal újratöltődik.)
 */
trait Uzenetek
{
    public ?string $uzenet = null;

    public ?string $uzenetTipus = null;

    public function uzenet(string $szoveg, string $tipus = 'siker'): void
    {
        $this->uzenet = $szoveg;
        $this->uzenetTipus = $tipus;
    }

    public function uzenetTorles(): void
    {
        $this->uzenet = null;
        $this->uzenetTipus = null;
    }
}
