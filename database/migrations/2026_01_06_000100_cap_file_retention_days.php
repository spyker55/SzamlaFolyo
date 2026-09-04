<?php

declare(strict_types=1);

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A megőrzési idő plafonjának érvényesítése a már meglévő sorokon.
 *
 * A beállítás korábban 365 napig engedett. Az űrlap mostantól nem enged
 * ennyit, de attól a régi érték még ott ül az adatbázisban, és a
 * `fajl:selejtez` aszerint tartaná meg a fájlokat. A kódban lévő levágás
 * (`Company::megorzesiNapok()`) ezt önmagában is kezeli — ez a migráció azért
 * van, hogy a tárolt érték se hazudjon arról, mi történik valójában.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->where('file_retention_days', '>', Company::MEGORZES_MAX_NAP)
            ->update(['file_retention_days' => Company::MEGORZES_MAX_NAP]);
    }

    public function down(): void
    {
        // A levágott értékek nem állíthatók vissza: nem tudjuk, mi volt.
    }
};
