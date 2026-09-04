<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Egy ár a számlázónál, ahogy az **valóban** be van állítva.
 *
 * Az `egysegar` a pénznem legkisebb egységében áll, mert a Stripe is így
 * tárolja, és az átváltás pénznemenként más szabály — forintnál például az
 * összegnek százzal oszthatónak kell lennie, vagyis 1 990 Ft = 199 000. Ha itt
 * forintra váltanánk, a hiba, amit keresünk, épp az átváltásban tűnne el.
 */
final readonly class Ar
{
    public function __construct(
        public int $egysegar,
        public string $penznem,
        public bool $ismetlodo,
        public bool $aktiv,
    ) {}
}
