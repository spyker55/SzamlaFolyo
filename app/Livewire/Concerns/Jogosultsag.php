<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\Szerep;
use App\Support\Berlo;

/**
 * Szerepkör-ellenőrzés a képernyők írási műveleteihez.
 *
 * A korlátot **a műveletben** kell megfogni, nem az elrejtett gombbal: egy
 * Livewire-akció közvetlenül is meghívható a böngészőből, a rejtett gomb csak
 * annyit ér, hogy nem kínáljuk fel. Ezért a nézetek is elrejtik, amit a
 * felhasználó nem tehet meg — de a döntés itt születik.
 *
 * A két jog a Szerep enumból jön, és ott van leírva, mit takar:
 * `szerkeszthet()` a napi munka, `adminisztralhat()` a visszafordíthatatlan.
 */
trait Jogosultsag
{
    /** Feltöltés, javítás, jóváhagyás, export. */
    private function kellSzerkeszto(): void
    {
        $this->kellSzerep(fn (Szerep $s): bool => $s->szerkeszthet());
    }

    /** Számlázás, tagok kezelése, végleges törlés. */
    private function kellTulajdonos(): void
    {
        $this->kellSzerep(fn (Szerep $s): bool => $s->adminisztralhat());
    }

    /** Igaz, ha a belépett felhasználó dolgozhat is, nem csak nézhet. */
    public function szerkeszthet(): bool
    {
        $ceg = app(Berlo::class)->ceg();

        return $ceg !== null && (auth()->user()?->szerepe($ceg)?->szerkeszthet() ?? false);
    }

    /** Igaz, ha a belépett felhasználó a cég tulajdonosa. */
    public function adminisztralhat(): bool
    {
        $ceg = app(Berlo::class)->ceg();

        return $ceg !== null && (auth()->user()?->szerepe($ceg)?->adminisztralhat() ?? false);
    }

    private function kellSzerep(callable $feltetel): void
    {
        $ceg = app(Berlo::class)->kotelezo();
        $szerep = auth()->user()?->szerepe($ceg);

        abort_unless($szerep !== null && $feltetel($szerep), 403);
    }
}
