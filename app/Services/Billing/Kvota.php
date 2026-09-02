<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Mennyi maradt a havi keretből.
 *
 * Nincs számláló, amit resetelni kellene: a felhasznált darabszám mindig
 * lekérdezés az aktuális időszakra. Egy számláló elcsúszhat (kétszer nő, vagy
 * elfelejtjük nullázni), egy lekérdezés nem tud.
 *
 * Egy dokumentum akkor fogyaszt a keretből, ha **ténylegesen kiolvastuk** —
 * a duplikátum és a feltöltés utáni azonnali hiba nem számít bele, mert azért
 * nem fizettünk a modellnek.
 */
final class Kvota
{
    public function __construct(private readonly Company $ceg) {}

    public function keret(): int
    {
        if ($this->ceg->elofizetettE()) {
            foreach ((array) config('szamlafolyo.plans') as $csomag) {
                if (($csomag['price_id'] ?? null) !== null && $csomag['price_id'] === $this->ceg->stripe_price_id) {
                    return (int) $csomag['documents'];
                }
            }

            // Aktív előfizetés ismeretlen árazonosítóval: inkább engedjük
            // dolgozni, mint hogy egy fizető ügyfelet állítsunk meg.
            return PHP_INT_MAX;
        }

        return $this->ceg->probaidosE() ? (int) config('szamlafolyo.trial.documents') : 0;
    }

    public function felhasznalt(): int
    {
        [$tol, $ig] = $this->idoszak();

        return Document::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->ceg->id)
            ->whereBetween('created_at', [$tol, $ig])
            ->whereHas('extractions')
            ->count();
    }

    public function maradek(): int
    {
        $keret = $this->keret();

        return $keret === PHP_INT_MAX ? PHP_INT_MAX : max(0, $keret - $this->felhasznalt());
    }

    public function vanMegKeret(): bool
    {
        return $this->maradek() > 0;
    }

    /** Miért nem mehet tovább — ezt írjuk ki a felületen, szó szerint. */
    public function akadaly(): ?string
    {
        if ($this->vanMegKeret()) {
            return null;
        }

        if (! $this->ceg->elofizetettE() && ! $this->ceg->probaidosE()) {
            return 'A próbaidő lejárt. A feldolgozás folytatásához válassz csomagot a Beállításokban.';
        }

        return sprintf(
            'Elfogyott a havi kereted (%d dokumentum). A feltöltött iratok megvárják a következő időszakot, vagy válts nagyobb csomagra.',
            $this->keret(),
        );
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function idoszak(): array
    {
        if ($this->ceg->elofizetettE() && $this->ceg->current_period_start && $this->ceg->current_period_end) {
            return [$this->ceg->current_period_start, $this->ceg->current_period_end];
        }

        // Próbaidőben a teljes próbaidőszak egyetlen keret.
        return [
            $this->ceg->created_at ?? now()->subYear(),
            $this->ceg->trial_ends_at ?? now(),
        ];
    }
}
