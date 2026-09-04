<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use Illuminate\Support\Carbon;
use RuntimeException;
use Stripe\StripeClient;

/**
 * A Stripe-kapcsolat. Nem építünk saját számlázási felületet: a fizetés a
 * Stripe Checkoutban, a kezelés (kártyacsere, lemondás, számlák) a Stripe
 * ügyfélportálján történik. Nekünk elég az előfizetés állapotát tudni.
 */
final class StripeSzolgaltatas implements SzamlazoKapu
{
    private function kliens(): StripeClient
    {
        $titok = (string) config('stripe.secret');

        if ($titok === '') {
            throw new RuntimeException('Nincs beállítva a Stripe titkos kulcs.');
        }

        return new StripeClient($titok);
    }

    public function beallitva(): bool
    {
        return (string) config('stripe.secret') !== '';
    }

    /** A cég Stripe-ügyfele; ha még nincs, most jön létre. */
    public function ugyfel(Company $ceg, string $email): string
    {
        if ($ceg->stripe_customer_id !== null) {
            return $ceg->stripe_customer_id;
        }

        $ugyfel = $this->kliens()->customers->create([
            'name' => $ceg->name,
            'email' => $email,
            'metadata' => ['company_id' => (string) $ceg->id],
        ]);

        $ceg->update(['stripe_customer_id' => $ugyfel->id]);

        return $ugyfel->id;
    }

    public function checkoutUrl(Company $ceg, string $email, string $priceId, string $sikerUrl, string $megseUrl): string
    {
        $munkamenet = $this->kliens()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $this->ugyfel($ceg, $email),
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $sikerUrl,
            'cancel_url' => $megseUrl,
            'client_reference_id' => (string) $ceg->id,
            'subscription_data' => ['metadata' => ['company_id' => (string) $ceg->id]],
            'allow_promotion_codes' => true,
        ]);

        return (string) $munkamenet->url;
    }

    public function portalUrl(Company $ceg, string $email, string $visszaUrl): string
    {
        $munkamenet = $this->kliens()->billingPortal->sessions->create([
            'customer' => $this->ugyfel($ceg, $email),
            'return_url' => $visszaUrl,
        ]);

        return (string) $munkamenet->url;
    }

    /**
     * Az előfizetés állapotának átvétele a Stripe-tól. Az időszak határai
     * azért kellenek, mert a keret ezekre az időpontokra számol — nem naptári
     * hónapra.
     */
    public function elofizetestFrissit(Company $ceg, object $elofizetes): void
    {
        $tetel = $elofizetes->items->data[0] ?? null;

        $ceg->update([
            'stripe_subscription_id' => $elofizetes->id,
            'stripe_status' => $elofizetes->status,
            'stripe_price_id' => $tetel?->price?->id,
            'current_period_start' => isset($elofizetes->current_period_start)
                ? Carbon::createFromTimestamp($elofizetes->current_period_start)
                : ($tetel?->current_period_start
                    ? Carbon::createFromTimestamp($tetel->current_period_start)
                    : null),
            'current_period_end' => isset($elofizetes->current_period_end)
                ? Carbon::createFromTimestamp($elofizetes->current_period_end)
                : ($tetel?->current_period_end
                    ? Carbon::createFromTimestamp($tetel->current_period_end)
                    : null),
        ]);
    }

    public function elofizetes(string $id): object
    {
        return $this->kliens()->subscriptions->retrieve($id, []);
    }

    /**
     * A keret fölötti darabok felvitele a következő számlára.
     *
     * Nem külön fizetés: a Stripe a függő tételt magától ráteszi az
     * előfizetés soron következő számlájára. Így a felhasználó egy számlát
     * kap, és a tétel ott áll rajta, nem egy váratlan külön terhelésként.
     *
     * @return string a létrejött tétel Stripe-azonosítója
     */
    public function extraTetel(Company $ceg, string $email, string $priceId, int $darab): string
    {
        $tetel = $this->kliens()->invoiceItems->create([
            'customer' => $this->ugyfel($ceg, $email),
            'price' => $priceId,
            'quantity' => $darab,
            'metadata' => ['company_id' => (string) $ceg->id],
        ]);

        return (string) $tetel->id;
    }
}
