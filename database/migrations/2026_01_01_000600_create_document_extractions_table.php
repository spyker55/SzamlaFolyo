<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A gépi kiolvasás eredménye. Soha nem írjuk felül a javított értékkel: a
     * gépi és az emberi érték külön él, különben nem mérhető, mennyit javult a
     * modell egy prompt- vagy modellcsere után.
     */
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->string('model')->nullable();          // amit kértünk
            $table->string('model_version')->nullable();  // amit a szolgáltató ténylegesen futtatott
            $table->string('prompt_version', 40);

            $table->json('raw_response')->nullable();
            $table->json('fields')->nullable();
            $table->json('confidence')->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
