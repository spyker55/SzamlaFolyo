<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Az oldalalapú keretmérés és a túlhasználat.
 *
 * A `credits` azért a kiolvasás sorára kerül és nem a dokumentumra: a keret
 * abból számol, amit a felhasználó **nem tud eltüntetni**. A dokumentum
 * törölhető, a modellhívás megtörténte nem tehető meg nem történtté.
 *
 * Az alapérték `1`: a migráció előtt keletkezett sorok pontosan annyit
 * fogyasztottak, amennyit akkor számoltunk nekik. Visszamenőleg nem
 * terhelünk senkit.
 *
 * A kiszámlázott túlhasználat külön táblába kerül, nem a kiolvasás sorára.
 * Egy több oldalas irat több kreditet ér, és épp ráeshet a kerethatárra: ha a
 * soron jelölnénk a számlázást, azt kellene eldönteni, hogy a sor „fele"
 * számlázott-e. Időszakonként összegzett kreditmennyiséggel ez a kérdés fel
 * sem merül, és a `stripe_invoice_item_id` mellett a terhelés vissza is
 * kereshető.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->unsignedInteger('credits')->default(1)->after('duration_ms');
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // Alapból kikapcsolva, és ez szándékos: a keret elfogyásakor a
            // rendszer megáll. Váratlan számlát senki ne kapjon attól, hogy
            // egy hónapban többet dolgozott.
            $table->boolean('overage_enabled')->default(false);
        });

        Schema::create('overage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Az elszámolási időszak kezdete. Ez köti a terhelést ahhoz a
            // kerethez, amelyiket túllépte — naptári hónap helyett, mert az
            // előfizetés ciklusa nem naptári.
            $table->timestamp('period_start');
            $table->unsignedInteger('credits');
            $table->string('stripe_invoice_item_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overage_charges');

        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropColumn('credits');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('overage_enabled');
        });
    }
};
