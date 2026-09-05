<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A böngészőnek szóló védelmi utasítások, minden válaszon.
 *
 * # Miért nincs teljes CSP
 *
 * A `script-src` szigorítása itt ma **látszatvédelem** lenne. Az Alpine az
 * `x-on:` és `x-show` kifejezéseket `new Function()`-nel értékeli ki, tehát
 * `unsafe-eval` nélkül a felület megáll; a Livewire pedig beágyazott
 * szkriptet injektál, ami `unsafe-inline`-t kíván. Egy `unsafe-inline
 * unsafe-eval` CSP viszont pontosan azt engedi meg, ami ellen a CSP szól —
 * csak közben azt a hamis érzést kelti, hogy meg van oldva. Ha egyszer
 * áttérünk a CSP-barát Alpine-építésre, itt a helye.
 *
 * Ami marad, az viszont valódi és nem kerül semmibe:
 *
 * - `frame-ancestors 'self'` (és a régi böngészőknek `X-Frame-Options`):
 *   clickjacking ellen. **`SAMEORIGIN`, nem `DENY`** — az ellenőrző képernyő
 *   a bizonylatot saját eredetű iframe-ben mutatja, `DENY` a saját nézetünket
 *   ölné meg.
 * - `nosniff`: a böngésző ne találgassa a tartalomtípust. Ez már ott van a
 *   fájlkiszolgáló válaszán, de nem csak ott számít.
 * - `Referrer-Policy`: az iratok URL-jei sorszámot hordoznak; ne szivárogjanak
 *   ki idegen oldalak naplójába.
 * - HSTS, csak élesben és csak https-en. Egy tanúsítvány nélküli fejlesztői
 *   gépet ezzel ki lehetne zárni magunkból: a böngésző hónapokra megjegyzi.
 */
final class BiztonsagiFejlecek
{
    public function handle(Request $request, Closure $next): Response
    {
        $valasz = $next($request);

        $valasz->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $valasz->headers->set('X-Content-Type-Options', 'nosniff', false);
        $valasz->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $valasz->headers->set('Content-Security-Policy', "frame-ancestors 'self'", false);

        if (app()->environment('production') && $request->isSecure()) {
            $valasz->headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $valasz;
    }
}
