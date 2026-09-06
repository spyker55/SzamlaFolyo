<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\Company;
use App\Services\Files\FajlTarolo;
use Illuminate\Support\Facades\Storage;

/**
 * Egy cég és minden hozzá tartozó adat végleges törlése.
 *
 * Két hívója van — a `ceg:torol` parancs (hibajavítás) és a fióktörlés
 * (a felhasználó kérése) —, és pont ezért van külön: a lemezen maradó
 * fájlokról egyszer szabad megfeledkezni, kétszer nem. Korábban a parancs
 * csak az `iratok/` mappát vitte el, az `exportok/` gazdátlanul ott maradt;
 * a két hívó összevonása ezt is megszünteti.
 */
final class CegTorlo
{
    /**
     * Az adatbázissorok a `cascadeOnDelete` mentén maguktól elmennek
     * (bizonylatok, kiolvasások, javítások, exportok, napló, beérkezett
     * levelek, túlhasználati tételek, tagságok). A fájlok nem: azok pont
     * idegen cégek számlái, amikről a legkevésbé szabad másolatot hagyni
     * egy osztott tárhelyen.
     */
    public function torol(Company $ceg): void
    {
        $lemez = Storage::disk(FajlTarolo::LEMEZ);

        foreach (["iratok/{$ceg->id}", "exportok/{$ceg->id}"] as $mappa) {
            if ($lemez->exists($mappa)) {
                $lemez->deleteDirectory($mappa);
            }
        }

        $ceg->delete();
    }
}
