<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CegValasztas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Váltás egy másik cégre.
 *
 * Rendes űrlapbeküldés, nem Livewire-akció, és teljes újratöltéssel jár. Ez
 * szándékos: cégváltáskor a képernyőn lévő minden komponens állapota — szűrők,
 * kijelölések, félig kitöltött ellenőrzés — az **előző** cég adataira
 * vonatkozik. Egy részleges frissítés ezek egy részét meghagyná, és a
 * legrosszabb fajta hibát szülné: két cég adatait egy képernyőn.
 *
 * A cél mindig a Beérkező, sosem az előző oldal: az előző oldal lehetett egy
 * bizonylat ellenőrzése, ami a másik cégben nem létezik.
 */
final class CegValtasController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $adatok = $request->validate(['ceg' => ['required', 'integer']]);

        $user = $request->user();

        // A tagságot a `CegValasztas` ellenőrzi. Ha nem tagja, nem váltunk —
        // a munkamenetbe írt idegen azonosító nem cégváltás.
        $ceg = $user === null ? null : CegValasztas::valaszt($user, (int) $adatok['ceg']);

        abort_if($ceg === null, 403);

        return redirect()->route('beerkezo')
            ->with('siker', "Átváltottál a(z) „{$ceg->name}” cégre.");
    }
}
