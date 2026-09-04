<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\DocumentExtraction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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

    /**
     * A havi darabkeret.
     *
     * Korlátlan csomag **nincs**, és korábban véletlenül volt: aktív
     * előfizetés ismeretlen árazonosítóval `PHP_INT_MAX`-ot kapott. Egy
     * Stripe-ban létrehozott, de az `.env`-be be nem írt ár így csendben
     * korlátlan keretté vált — épp az AI-költséges oldalon. Ismeretlen ár
     * mostantól a legkisebb csomag keretét kapja, és naplózzuk, hogy
     * kiderüljön a beállítási hiba.
     */
    public function keret(): int
    {
        if ($this->ceg->elofizetettE()) {
            $csomag = $this->ceg->csomag();

            if ($csomag !== null) {
                return (int) $csomag['documents'];
            }

            Log::warning('Ismeretlen Stripe árazonosító, a legkisebb csomag keretét adjuk', [
                'company_id' => $this->ceg->id,
                'price_id' => $this->ceg->stripe_price_id,
            ]);

            return (int) config('szamlafolyo.plans.kicsi.documents');
        }

        return $this->ceg->probaidosE() ? (int) config('szamlafolyo.trial.documents') : 0;
    }

    /**
     * A felhasznált keret **kreditben**, nem sorban.
     *
     * A vevő dokumentumot vásárol, a költségünk viszont oldalarányos: egy
     * nyolcvan oldalas köteg nem egy nyugta. A `credits` oszlopot a kiolvasás
     * írja, a szabály az `App\Support\Kredit`-ben áll — egy helyen, mert ki
     * is van írva a felületre.
     */
    public function felhasznalt(): int
    {
        [$tol, $ig] = $this->idoszak();

        return (int) $this->idoszakSorai($tol, $ig)->sum('credits');
    }

    public function maradek(): int
    {
        return max(0, $this->keret() - $this->felhasznalt());
    }

    /**
     * Mehet-e tovább a feldolgozás.
     *
     * A keret fölött csak akkor, ha a tulajdonos **külön engedélyezte** a
     * darabonként számlázott túlhasználatot. Alapból nem: váratlan számlát
     * senki ne kapjon attól, hogy egy hónapban többet dolgozott.
     */
    public function vanMegKeret(): bool
    {
        return $this->maradek() > 0 || $this->ceg->tulhasznalatEngedve();
    }

    /** A keret fölötti, tehát darabonként számlázandó kreditek száma. */
    public function tullepes(): int
    {
        return max(0, $this->felhasznalt() - $this->keret());
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

        if (! $this->ceg->elofizetettE()) {
            return sprintf(
                'Elfogyott a próbaidős kereted (%d dokumentum). A folytatáshoz válassz csomagot a Beállításokban.',
                $this->keret(),
            );
        }

        return sprintf(
            'Elfogyott a havi kereted (%d dokumentum). A feltöltött iratok megvárják a következő időszakot, '
            .'válts nagyobb csomagra, vagy engedélyezd a Beállításokban a keret fölötti feldolgozást.',
            $this->keret(),
        );
    }

    /**
     * @return Builder<DocumentExtraction>
     */
    public function idoszakSorai(Carbon $tol, Carbon $ig)
    {
        return DocumentExtraction::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->ceg->id)
            ->whereBetween('created_at', [$tol, $ig])
            ->whereNull('error');
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
