<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A keret fölötti feldolgozás forintban mért felső határa.
 *
 * A `overage_enabled` kapcsoló önmagában nyitott végű: bekapcsolva egyetlen
 * elgépelt tömeges feltöltés is tetszőleges összegű extra tételt tud a
 * következő számlára tenni. A plafon az a fék, amitől a kapcsoló vállalható.
 *
 * `null` = nincs plafon. Ez a régi viselkedés, de **nem** ez az alapérték:
 * a bekapcsoláskor a konfigurációból kap egy értéket, és onnantól tudatos
 * döntés kell a kiürítéséhez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $tabla) {
            $tabla->unsignedInteger('overage_limit_ft')->nullable()->after('overage_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $tabla) {
            $tabla->dropColumn('overage_limit_ft');
        });
    }
};
