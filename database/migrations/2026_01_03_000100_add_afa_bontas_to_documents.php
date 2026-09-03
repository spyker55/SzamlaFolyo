<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kulcsonkénti ÁFA-bontás és fizetendő összeg — az EN 16931 két olyan
     * adata, ami nélkül a séma nem kanonikus, csak OCR-kimenet.
     *
     * A bontás nélkül egy 27%-os és egy 5%-os tételt tartalmazó számla a három
     * végösszegből visszafejthetetlen, pedig a könyvelőnek épp ez kell az
     * ÁFA-bevalláshoz — és a strukturált számlaformátumok (Factur-X, ZUGFeRD,
     * UBL) készen hozzák.
     *
     * Nem külön tábla: a bontás mindig az irattal együtt olvasódik, sosem
     * önállóan kérdezzük le, és jellemzően 1–3 sor. Ugyanaz a json-minta,
     * mint a `forras_naplo`.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->json('afa_bontas')->nullable();

            // EN 16931 BT-115. Eltér a bruttótól, ha kerekítettek, vagy ha
            // levontak belőle korábban fizetett előleget.
            $table->decimal('fizetendo', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['afa_bontas', 'fizetendo']);
        });
    }
};
