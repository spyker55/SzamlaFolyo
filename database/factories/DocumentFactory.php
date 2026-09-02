<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'status' => DokumentumAllapot::Feltoltve->value,
            'source' => 'upload',
            'original_filename' => 'szamla.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', uniqid('', true)),
            'storage_path' => 'iratok/teszt/'.uniqid().'.pdf',
        ];
    }

    public function jovahagyva(): static
    {
        return $this->state(fn (): array => [
            'status' => DokumentumAllapot::Jovahagyva->value,
            'doc_type' => DokumentumTipus::Szamla->value,
            'supplier_name' => 'Példa Szállító Kft.',
            'supplier_tax_number' => '10773381-2-44',
            'customer_name' => 'Vevő Zrt.',
            'doc_number' => 'SZ-'.$this->faker->numberBetween(1000, 9999),
            'issue_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'currency' => 'HUF',
            'net_amount' => '100000.00',
            'vat_amount' => '27000.00',
            'gross_amount' => '127000.00',
            'approved_at' => now(),
        ]);
    }

    public function ellenorzesreVar(): static
    {
        return $this->state(fn (): array => [
            'status' => DokumentumAllapot::EllenorzesreVar->value,
        ]);
    }
}
