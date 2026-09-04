<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kiolvasás sora éli túl a dokumentumot.
 *
 * A keret eddig a `documents` táblából számolt, vagyis olyan sorokból, amiket a
 * felhasználó **maga törölhet** — a Beérkezőből, az Archívumból, vagy egy egész
 * export törlésével, ami a hozzá tartozó tételeket is elviszi. Aki tehát
 * exportált és utána rendet rakott, annak a felhasznált darabszám visszaugrott
 * nullára. Egy próbaidős keret így korlátlan.
 *
 * A modellhívásért viszont már fizettünk, és ezt egy törlés nem teheti meg nem
 * történtté. Ezért a kiolvasás sora marad: a `document_id` nullázódik, a sor
 * nem. Ez egyben az az audit-nyom is, amiből utólag kiderül, mit csinált a
 * modell — annak sem szabadna eltűnnie azzal, hogy valaki takarít.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table): void {
            $table->dropForeign(['document_id']);
            $table->unsignedBigInteger('document_id')->nullable()->change();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table): void {
            $table->dropForeign(['document_id']);
            $table->unsignedBigInteger('document_id')->nullable(false)->change();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
        });
    }
};
