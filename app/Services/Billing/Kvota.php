<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\DocumentExtraction;
use Illuminate\Support\Carbon;

/**
 * Mennyi maradt a havi keretből.
 *
 * Nincs számláló, amit resetelni kellene: a felhasznált darabszám mindig
 * lekérdezés az aktuális időszakra. Egy számláló elcsúszhat (kétszer nő, vagy
 * elfelejtjük nullázni), egy lekérdezés nem tud.
 *
 * De **azt kell megkérdezni, amit a felhasználó nem tud eltüntetni.** A
 * darabszám korábban a `documents` táblából jött, vagyis olyan sorokból, amiket
 * a Beérkezőből, az Archívumból vagy egy export törlésével bárki elvihet — aki
 * exportált és utána rendet rakott, annak a felhasznált szám visszaugrott
 * nullára, és a próbaidős keret gyakorlatilag korlátlan lett. Ezért a
 * `document_extractions` a forrás: a modellhívás megtörténtét egy takarítás nem
 * teheti meg nem történtté.
 *
 * Egy dokumentum akkor fogyaszt a keretből, ha **ténylegesen kiolvastuk**: csak
 * a hibátlan kiolvasás számít. A duplikátum meg sem jut idáig, a hibába futott
 * kísérlet (lejárt kulcs, olvashatatlan fájl, újrapróbálkozás) pedig nem a
 * felhasználó hibája, és jórészt nem is került pénzbe.
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

        return DocumentExtraction::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->ceg->id)
            ->whereBetween('created_at', [$tol, $ig])
            ->whereNull('error')
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

        // Próbaidőben a teljes próbaidőszak egyetlen keret. A vég nem a
        // `trial_ends_at`, hanem a mai nap, ha az későbbi: a lejárat után a
        // felhasznált darabszám ne ugorjon vissza nullára a képernyőn — a
        // keretet a `keret()` zárja le, nem az, hogy elrejtjük a fogyást.
        $vege = $this->ceg->trial_ends_at ?? now();

        return [
            $this->ceg->created_at ?? now()->subYear(),
            $vege->isFuture() ? $vege : now(),
        ];
    }
}
