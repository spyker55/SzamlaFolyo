<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('status', 30)->default('feltoltve');
            $table->string('doc_type', 30)->nullable();
            $table->string('source', 20)->default('upload');   // upload | email

            // A bizonylat két oldala. Nem egy „partner”: egy bejövő számlán a
            // szállító az idegen fél, egy kimenőn a vevő — és a könyvelőnek
            // mindkettő kell.
            $table->string('supplier_name')->nullable();
            $table->string('supplier_tax_number', 30)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_number', 30)->nullable();

            $table->string('doc_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('fulfillment_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('payment_method', 40)->nullable();

            $table->char('currency', 3)->nullable();
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->decimal('gross_amount', 15, 2)->nullable();

            $table->text('note')->nullable();

            // Egy PDF-ben több bizonylat is jöhet. Az első verzióban elég, ha a
            // modell jelzi, és az ember szétvágja.
            $table->boolean('tobb_irat_gyanu')->default(false);

            // Fájl. A `storage_path` az export után kiürül, a `sha256` marad —
            // abból tudjuk, hogy ugyanazt a bizonylatot már láttuk.
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->timestamp('file_deleted_at')->nullable();

            $table->foreignId('export_id')->nullable()->constrained('exports')->nullOnDelete();
            $table->foreignId('inbound_email_id')->nullable()->constrained('inbound_emails')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Sorbaállítás worker nélkül: a claim egy feltételes UPDATE.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'sha256']);
            $table->index(['company_id', 'export_id']);
            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
