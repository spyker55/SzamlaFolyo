<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->char('default_currency', 3)->default('HUF');

            // A beérkeztető cím kitalálhatatlan része. Ez dönti el, melyik
            // céghez tartozik egy beérkező levél — soha nem a feladó.
            $table->string('inbox_token', 32)->unique();

            // Próbaidő Stripe nélkül: kártya nélkül lehet kipróbálni.
            $table->timestamp('trial_ends_at')->nullable();

            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_status')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // 0 = az eredeti fájl az exporttal egy időben törlődik.
            $table->unsignedSmallInteger('file_retention_days')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
