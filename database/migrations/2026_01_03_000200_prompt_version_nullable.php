<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A prompt verziója mostantól hiányozhat.
     *
     * Az oszlop azért van, hogy prompt- vagy modellcsere után csak azonos
     * verziójú futásokat hasonlítsunk össze. Az XML-értelmező viszont nem hív
     * modellt, tehát nincs prompt-verziója — és odaírni egy olyat, ami nem
     * futott, épp azt az összehasonlítást rontaná el, amiért az oszlop van.
     * A null itt tartalmi állítás: ezt nem modell olvasta ki.
     */
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->string('prompt_version', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->string('prompt_version', 40)->nullable(false)->change();
        });
    }
};
