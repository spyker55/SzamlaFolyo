<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToCompany, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DokumentumAllapot::class,
            'doc_type' => DokumentumTipus::class,
            'issue_date' => 'date',
            'fulfillment_date' => 'date',
            'due_date' => 'date',
            'file_deleted_at' => 'datetime',
            'approved_at' => 'datetime',
            'claimed_at' => 'datetime',
            'tobb_irat_gyanu' => 'boolean',
            'forras_naplo' => 'array',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
        ];
    }

    /** A mezők, amikért az egész alkalmazás van: ezeket olvassa ki az AI és ezek mennek exportba. */
    public const KIOLVASOTT_MEZOK = [
        'doc_type',
        'supplier_name',
        'supplier_tax_number',
        'customer_name',
        'customer_tax_number',
        'doc_number',
        'issue_date',
        'fulfillment_date',
        'due_date',
        'payment_method',
        'currency',
        'net_amount',
        'vat_amount',
        'gross_amount',
    ];

    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class);
    }

    public function utolsoKiolvasas(): ?DocumentExtraction
    {
        return $this->extractions()->latest('id')->first();
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(Export::class);
    }

    public function inboundEmail(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function vanFajlja(): bool
    {
        return $this->storage_path !== null && $this->file_deleted_at === null;
    }

    public function kepE(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function megnevezes(): string
    {
        return $this->doc_number
            ?: ($this->original_filename ?: 'Dokumentum #'.$this->id);
    }

    /** @param  Builder<self>  $query */
    public function scopeBeerkezo(Builder $query): void
    {
        $query->whereIn('status', DokumentumAllapot::beerkezoErtekek());
    }

    /** @param  Builder<self>  $query */
    public function scopeExportalhato(Builder $query): void
    {
        $query->where('status', DokumentumAllapot::Jovahagyva->value)->whereNull('export_id');
    }
}
