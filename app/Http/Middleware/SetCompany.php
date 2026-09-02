<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Berlo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A bérlő beállítása a kérés elején. Innentől minden céghez kötött lekérdezés
 * erre a cégre szűkül — a képernyők nem tudnak „elfelejteni” szűrni.
 *
 * Akinek nincs cége, azt a cégnyitó képernyőre küldjük.
 */
final class SetCompany
{
    public function __construct(private readonly Berlo $berlo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ceg = $request->user()?->ceg();

        if ($ceg === null) {
            return redirect()->route('ceg.letrehozas');
        }

        $this->berlo->beallit($ceg);

        return $next($request);
    }
}
