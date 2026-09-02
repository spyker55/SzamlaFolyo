<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Kft.',
            'tax_number' => '10773381-2-44',
            'default_currency' => 'HUF',
            'inbox_token' => bin2hex(random_bytes(8)),
            'trial_ends_at' => now()->addDays(14),
            'file_retention_days' => 0,
        ];
    }

    public function elofizetett(string $priceId = 'price_kozepes'): static
    {
        return $this->state(fn (): array => [
            'stripe_customer_id' => 'cus_'.uniqid(),
            'stripe_subscription_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price_id' => $priceId,
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
            'trial_ends_at' => now()->subDay(),
        ]);
    }

    public function lejartProbaido(): static
    {
        return $this->state(fn (): array => [
            'trial_ends_at' => now()->subDay(),
        ]);
    }
}
