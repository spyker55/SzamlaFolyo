<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Billing\StripeSzolgaltatas;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * A Stripe értesítései. Ez hitelesítetlen írási út kívülről, ezért az első
 * dolog az aláírás ellenőrzése — a kérés tartalmának addig semmilyen
 * jelentőséget nem tulajdonítunk.
 */
final class StripeWebhookController
{
    public function __invoke(Request $request, StripeSzolgaltatas $stripe): Response
    {
        $titok = (string) config('stripe.webhook_secret');

        if ($titok === '') {
            return response('A webhook nincs beállítva.', 503);
        }

        try {
            // Az aláírás a nyers bájtokra vonatkozik: a feldolgozott
            // tömbből már nem lehetne visszaállítani.
            $esemeny = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $titok,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('Érvénytelen aláírás.', 400);
        }

        $objektum = $esemeny->data->object;

        match ($esemeny->type) {
            'checkout.session.completed' => $this->checkoutKesz($objektum, $stripe),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->elofizetesValtozott($objektum, $stripe),
            default => null,
        };

        return response('ok', 200);
    }

    private function checkoutKesz(object $munkamenet, StripeSzolgaltatas $stripe): void
    {
        $ceg = $this->ceg($munkamenet->client_reference_id ?? null, $munkamenet->customer ?? null);

        if ($ceg === null || empty($munkamenet->subscription)) {
            return;
        }

        $stripe->elofizetestFrissit($ceg, $stripe->elofizetes((string) $munkamenet->subscription));
    }

    private function elofizetesValtozott(object $elofizetes, StripeSzolgaltatas $stripe): void
    {
        $ceg = $this->ceg(
            $elofizetes->metadata->company_id ?? null,
            $elofizetes->customer ?? null,
        );

        if ($ceg === null) {
            return;
        }

        $stripe->elofizetestFrissit($ceg, $elofizetes);
    }

    /** A céget elsősorban a saját azonosítónk alapján keressük, másodsorban az ügyfélazonosító alapján. */
    private function ceg(mixed $cegId, mixed $ugyfelId): ?Company
    {
        if ($cegId !== null && $cegId !== '') {
            $ceg = Company::query()->find((int) $cegId);

            if ($ceg !== null) {
                return $ceg;
            }
        }

        if (is_string($ugyfelId) && $ugyfelId !== '') {
            return Company::query()->where('stripe_customer_id', $ugyfelId)->first();
        }

        return null;
    }
}
