<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * Melyik céghez tartozik egy beérkező levél.
 *
 * A választ **a címzett** adja meg, soha nem a feladó: a feladó cím
 * hamisítható, a kitalálhatatlan token a címzettben viszont bizonyíték arra,
 * hogy a beküldő megkapta a cég beküldési címét.
 *
 * A `To` önmagában kevés: továbbküldött vagy titkos másolatként érkezett
 * levélnél a mi címünk csak a borítékon szerepel, ezért a `Delivered-To` és az
 * `X-Original-To` fejlécet is nézzük.
 */
final class CimzettToken
{
    /**
     * @param  array<string, string|array<string>>  $fejlecek
     */
    public static function kereses(array $fejlecek, string $mod, string $domain, ?string $plusCim = null): ?string
    {
        $cimek = [];

        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'to', 'cc', 'x-forwarded-to'] as $fejlec) {
            $ertek = $fejlecek[$fejlec] ?? $fejlecek[strtolower($fejlec)] ?? null;

            if ($ertek === null) {
                continue;
            }

            foreach ((array) $ertek as $sor) {
                $cimek = array_merge($cimek, self::cimekBontasa((string) $sor));
            }
        }

        foreach ($cimek as $cim) {
            $token = self::tokenCimbol($cim, $mod, $domain, $plusCim);

            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    private static function tokenCimbol(string $cim, string $mod, string $domain, ?string $plusCim): ?string
    {
        $cim = strtolower(trim($cim));

        if (! str_contains($cim, '@')) {
            return null;
        }

        [$helyi, $cimDomain] = explode('@', $cim, 2);

        if ($mod === 'plus') {
            if ($plusCim === null || $plusCim === '') {
                return null;
            }

            [$plusHelyi, $plusDomain] = array_pad(explode('@', strtolower($plusCim), 2), 2, '');

            if ($cimDomain !== $plusDomain || ! str_starts_with($helyi, $plusHelyi.'+')) {
                return null;
            }

            return self::ervenyesToken(substr($helyi, strlen($plusHelyi) + 1));
        }

        if ($cimDomain !== strtolower($domain)) {
            return null;
        }

        // A catch-all címben is előfordulhat plusz-címzés (`token+valami@`):
        // a plusz utáni rész a beküldő megjegyzése, nem a token része.
        $helyi = explode('+', $helyi, 2)[0];

        return self::ervenyesToken($helyi);
    }

    /** A token 16 hexa karakter — ami nem az, azt nem is keressük az adatbázisban. */
    private static function ervenyesToken(string $jelolt): ?string
    {
        $jelolt = trim($jelolt);

        return preg_match('/^[0-9a-f]{16}$/', $jelolt) === 1 ? $jelolt : null;
    }

    /** @return array<int, string> */
    private static function cimekBontasa(string $sor): array
    {
        preg_match_all('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/', $sor, $talalatok);

        return $talalatok[0] ?? [];
    }
}
