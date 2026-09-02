<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amit az ember átírt a gép után, mezőnként. Olcsó tábla, és idővel többet
     * ér, mint maga a szoftver: ebből derül ki, hol téved a modell.
     */
    public function up(): void
    {
        Schema::create('document_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extraction_id')->nullable()->constrained('document_extractions')->nullOnDelete();
            $table->string('field', 60);
            $table->text('machine_value')->nullable();
            $table->text('human_value')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_corrections');
    }
};
