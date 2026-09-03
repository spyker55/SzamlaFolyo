<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minden iratról feljegyezzük, honnan lehetett volna kiolvasni.
     *
     * Ez egyszerre a feldolgozási lánc döntése és mérés: ebből derül ki, hogy
     * a beérkező anyag mekkora részében van strukturált adat vagy szövegréteg
     * — vagyis mennyit lehet megspórolni azzal, hogy nem küldjük mindet drága
     * multimodális modellbe.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('forras_jelleg', 30)->nullable();
            $table->json('forras_naplo')->nullable();

            $table->index(['company_id', 'forras_jelleg']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'forras_jelleg']);
            $table->dropColumn(['forras_jelleg', 'forras_naplo']);
        });
    }
};
