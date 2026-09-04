<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bizonylat egészére vonatkozó figyelmeztetés: kézzel írott, elmosódott vagy
 * ferdén szkennelt irat.
 *
 * Miért kell külön: egy kézzel írott számlán minden mező átírás, és az átírás
 * hibája nem hagy nyomot, amit ellenőrizni lehetne. Egy valódi bizonylaton a
 * modell helyesen olvasta ki mindkét adószámot, a számlaszámot, mind a három
 * dátumot és az összegeket — a szállító nevét viszont **kitalálta**, és a
 * számtan hibátlan maradt, mert nulla ÁFA-nál nincs mit elrontani. Egyetlen
 * determinisztikus ellenőrzésünk sem tudott fogást találni rajta.
 *
 * Ez a mező nem a hibát találja meg, hanem azt mondja ki, hogy itt semmiért nem
 * tudunk jótállni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('nehezen_olvashato')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('nehezen_olvashato');
        });
    }
};
