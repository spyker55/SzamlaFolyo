<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Melyik cég nevében dolgozik éppen a felhasználó.
 *
 * A választás a munkamenetben él, **de a munkamenet nem hitelesítés**: az
 * ott álló azonosítót minden olvasásnál a tagsághoz mérjük. Egy elhagyott
 * vagy meghamisított érték így nem cégváltás, hanem semmi — visszaesünk az
 * első cégre.
 *
 * A feloldás azért **itt** van, és nem a fejlécben vagy a middleware-ben,
 * mert a route model binding (`BelongsToCompany::resolveRouteBinding`) is a
 * `User::ceg()`-et kérdezi, méghozzá a bérlő-middleware **előtt**. Ha a
 * kettő nem ugyanazt a céget adná, a felület a második cég listáját mutatná,
 * a megnyitott bizonylatot viszont az elsőben keresné.
 */
final class CegValasztas
{
    public const KULCS = 'valasztott_ceg_id';

    /** A most aktív cég: a választott, ha érvényes, különben az első. */
    public static function valasztott(User $user): ?Company
    {
        $id = self::munkamenetbol();

        if ($id !== null) {
            $ceg = self::tagsag($user, $id);

            if ($ceg !== null) {
                return $ceg;
            }

            // Már nem tagja (eltávolították, vagy a cég megszűnt). A választást
            // eldobjuk, hogy ne kérdezzük meg minden kérésben újra.
            self::felejt();
        }

        return $user->companies()->orderBy('companies.id')->first();
    }

    /** `null`, ha a felhasználó nem tagja a kért cégnek — ilyenkor nem váltunk. */
    public static function valaszt(User $user, int $cegId): ?Company
    {
        $ceg = self::tagsag($user, $cegId);

        if ($ceg === null) {
            return null;
        }

        session()->put(self::KULCS, $ceg->id);

        return $ceg;
    }

    public static function felejt(): void
    {
        if (self::vanMunkamenet()) {
            session()->forget(self::KULCS);
        }
    }

    private static function tagsag(User $user, int $cegId): ?Company
    {
        return $user->companies()->where('companies.id', $cegId)->first();
    }

    /**
     * Konzolon (cron, tinker) nincs munkamenet, és a `session()` hívása ott
     * kivételt dobna. A parancsok a `Berlo`-n át maguk állítják a céget.
     */
    private static function munkamenetbol(): ?int
    {
        if (! self::vanMunkamenet()) {
            return null;
        }

        $id = session()->get(self::KULCS);

        return is_numeric($id) ? (int) $id : null;
    }

    private static function vanMunkamenet(): bool
    {
        return app()->bound('session') && app('session')->isStarted();
    }
}
