<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Az e-mailes beküldés megszüntetése.
 *
 * A funkció kikerült a termékből: a bizonylat a Beérkezőbe töltve érkezik.
 * Ezzel a séma három eleme marad gazdátlanul, és a gazdátlan oszlop rosszabb
 * a hiányzónál — a következő olvasó azt hiszi, jelent valamit.
 *
 * **Amit ez a migráció véglegesen elvisz:** a beérkezett levelek metaadatai
 * (feladó, tárgy, `message_id`, mikor jött). Maguk a bizonylatok és a belőlük
 * kiolvasott adatok **érintetlenek** — az `inbound_emails` sorok csak azt
 * mondták meg, melyik levéllel érkeztek.
 *
 * A `documents.source` oszlop **szándékosan marad**. A megszüntetés előtt
 * érkezett sorok tényleg e-mailben jöttek, és egy megtörtént dolgot nem írunk
 * át utólag „feltöltés"-re. A Beérkező ezért továbbra is helyesen címkézi őket.
 *
 * A `down()` a szerkezetet visszaadja, az adatot nem tudja: ez a migráció
 * visszafordítható, de nem visszavonható.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A hivatkozás megy elsőnek, különben az idegen kulcs miatt a tábla
        // nem eldobható.
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['inbound_email_id']);
            $table->dropColumn('inbound_email_id');
        });

        Schema::dropIfExists('inbound_emails');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('inbox_token');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('inbox_token', 32)->nullable()->unique();
        });

        Schema::create('inbound_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->string('from_address')->nullable();
            $table->string('subject')->nullable();
            $table->unsignedSmallInteger('attachment_count')->default(0);
            $table->unsignedSmallInteger('document_count')->default(0);
            $table->string('status', 20)->default('erkezett');
            $table->text('error')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'message_id']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('inbound_email_id')->nullable()->constrained('inbound_emails')->nullOnDelete();
        });
    }
};
