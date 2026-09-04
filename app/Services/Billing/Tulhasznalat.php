<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\OverageCharge;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A keret fölötti darabok kiszámlázása.
 *
 * Szándékosan **nem** a feldolgozás közben fut. Két oka van. Egy Stripe-hívás
 * a kiolvasás útjába állítva azt jelentené, hogy egy hálózati hiba miatt egy
 * bizonylat nem dolgozódik fel — a felhasználó munkája nem múlhat a
 * számlázáson. Másrészt így a terhelés újrafuttatható: mindig azt nézzük,
 * mennyi a különbség a ténylegesen túllépett és a már kiszámlázott kreditek
 * között, tehát egy megszakadt futás után a következő pótolja, kétszer
 * terhelni viszont nem tud.
 *
 * Csak azoknál a cégeknél fut, amelyek **külön engedélyezték** a túlhasználatot.
 */
final class Tulhasznalat
{
    public function __construct(private readonly SzamlazoKapu $szamlazo) {}

    /** @return array{cegek: int, kreditek: int, hibak: int} */
    public function elszamol(): array
    {
        $cegek = 0;
        $kreditek = 0;
        $hibak = 0;

        foreach (Company::query()->where('overage_enabled', true)->cursor() as $ceg) {
            try {
                $darab = $this->cegre($ceg);
            } catch (Throwable $e) {
                $hibak++;
                Log::error('A túlhasználat elszámolása elakadt', [
                    'company_id' => $ceg->id,
                    'uzenet' => $e->getMessage(),
                ]);

                continue;
            }

            if ($darab > 0) {
                $cegek++;
                $kreditek += $darab;
            }
        }

        return ['cegek' => $cegek, 'kreditek' => $kreditek, 'hibak' => $hibak];
    }

    /** @return int hány kredit került most számlára */
    public function cegre(Company $ceg): int
    {
        if (! $ceg->tulhasznalatEngedve()) {
            return 0;
        }

        $csomag = $ceg->csomag();
        $priceId = $csomag['price_id_extra'] ?? null;

        if (! is_string($priceId) || $priceId === '') {
            // Engedélyezett túlhasználat, de nincs mögötte ár. Ilyenkor
            // **nem** találgatunk: a felhasználó ingyen dolgozik tovább, és
            // a napló megmondja, mit kell beállítani.
            Log::warning('Engedélyezett túlhasználat darabár nélkül', [
                'company_id' => $ceg->id,
                'csomag' => $csomag['nev'] ?? null,
            ]);

            return 0;
        }

        $kvota = new Kvota($ceg);
        [$tol] = $kvota->idoszak();

        $szamlazando = $kvota->tullepes() - $this->marSzamlazott($ceg, $tol);

        if ($szamlazando <= 0) {
            return 0;
        }

        $email = $this->szamlazasiEmail($ceg);

        if ($email === null) {
            Log::warning('Nincs kihez kötni a számlázást', ['company_id' => $ceg->id]);

            return 0;
        }

        $tetelId = $this->szamlazo->extraTetel($ceg, $email, $priceId, $szamlazando);

        OverageCharge::query()->create([
            'company_id' => $ceg->id,
            'period_start' => $tol,
            'credits' => $szamlazando,
            'stripe_invoice_item_id' => $tetelId,
        ]);

        return $szamlazando;
    }

    private function marSzamlazott(Company $ceg, \DateTimeInterface $tol): int
    {
        return (int) OverageCharge::query()
            ->withoutGlobalScopes()
            ->where('company_id', $ceg->id)
            ->where('period_start', $tol)
            ->sum('credits');
    }

    /**
     * A Stripe ügyfélhez e-mail kell. A cég tulajdonosáé a jó válasz — ő az,
     * aki a csomagot választotta és a számlát kapja.
     */
    private function szamlazasiEmail(Company $ceg): ?string
    {
        $email = $ceg->users()
            ->wherePivot('role', 'tulajdonos')
            ->orderBy('users.id')
            ->value('users.email');

        return is_string($email) && $email !== ''
            ? $email
            : $ceg->users()->orderBy('users.id')->value('users.email');
    }
}
