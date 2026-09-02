<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Idempotencia: ugyanaz a levél kétszer kézbesítve nem csinál
            // második tételt.
            $table->string('message_id');

            $table->string('from_address')->nullable();
            $table->string('subject')->nullable();
            $table->unsignedSmallInteger('attachment_count')->default(0);
            $table->unsignedSmallInteger('document_count')->default(0);

            // erkezett | feldolgozva | nincs_melleklet | elutasitva
            $table->string('status', 20)->default('erkezett');
            $table->text('error')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
