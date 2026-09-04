<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\User;
use App\Support\Adoszam;

/**
 * Ha egy irat nem ehhez a céghez tartozik, melyikhez tartozik?
 *
 * A „nem a te cégednek szól" jelzés önmagában zsákutca: a felhasználó látja,
 * hogy baj van, de nem tudja, mit kezdjen vele. Amióta egy fiók több céget is
 * kezelhet, a válasz gyakran ott van a saját cégei között — ezt a kérdést
 * korábban nem is lehetett feltenni.
 *
 * A vevő oldala az erősebb jel: az mondja meg, kinek szól a bizonylat. A
 * szállító oldala is számít, de mást jelent — ott a **mi** kimenő számlánkról
 * van szó, ami egy másik cégünké.
 */
final class CegAjanlas
{
    /**
     * A felhasználó másik cége, amelyhez ez az irat tartozik — vagy `null`.
     *
     * Nyers adószámokat kap, nem a dokumentumot: az ellenőrző képernyő a
     * **jelenlegi** űrlapértékekből kérdez, ahogy a validátorokat is
     * újrafuttatja. Ha az ember javítja az adószámot, az ajánlás is követi;
     * ha a tárolt sorból dolgoznánk, a piros jelzés és az ajánlás
     * ellentmondhatna egymásnak ugyanazon a képernyőn.
     */
    public static function talal(
        ?string $vevoAdoszam,
        ?string $szallitoAdoszam,
        User $felhasznalo,
        int $kizartCegId,
    ): ?Company {
        $vevo = self::torzsszam($vevoAdoszam);
        $szallito = self::torzsszam($szallitoAdoszam);

        if ($vevo === null && $szallito === null) {
            return null;
        }

        $cegek = $felhasznalo->cegei()
            ->reject(fn (Company $ceg): bool => $ceg->id === $kizartCegId);

        // A vevő oldala előbb: az mondja meg, kinek szól a bizonylat.
        foreach ([$vevo, $szallito] as $keresett) {
            if ($keresett === null) {
                continue;
            }

            $talalat = $cegek->first(
                fn (Company $ceg): bool => Adoszam::torzsszam($ceg->tax_number) === $keresett
            );

            if ($talalat !== null) {
                return $talalat;
            }
        }

        return null;
    }

    /**
     * Hibás ellenőrző számjegyű adószámra nem építünk következtetést — sem
     * terhelőt, sem mentesítőt, és javaslatot sem. Ez ugyanaz a szabály, amit
     * a `Validatorok` követ; egy félreolvasott számjegy nem irányíthat át egy
     * bizonylatot egy másik céghez.
     */
    private static function torzsszam(?string $adoszam): ?string
    {
        return Adoszam::biztosanRossz($adoszam) ? null : Adoszam::torzsszam($adoszam);
    }
}
