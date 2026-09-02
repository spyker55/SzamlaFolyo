<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use RuntimeException;

/**
 * Az aktuális cég. Egy kérésen belül egy cég van, és minden céghez kötött
 * lekérdezés ehhez szűkül (App\Models\Concerns\BelongsToCompany).
 *
 * Konténerben singletonként él; a webes kérésben a SetCompany middleware
 * állítja be, konzolon a parancs maga.
 */
final class Berlo
{
    private ?Company $ceg = null;

    public function beallit(?Company $ceg): void
    {
        $this->ceg = $ceg;
    }

    public function van(): bool
    {
        return $this->ceg !== null;
    }

    public function ceg(): ?Company
    {
        return $this->ceg;
    }

    public function id(): ?int
    {
        return $this->ceg?->id;
    }

    /** Ahol cég nélkül nincs értelmes válasz, ott inkább álljunk meg hangosan. */
    public function kotelezo(): Company
    {
        return $this->ceg ?? throw new RuntimeException('Nincs kiválasztott cég.');
    }

    /** Egy másik cég nevében futtat egy zárt szakaszt (cron, tesztek). */
    public function nevében(?Company $ceg, callable $muvelet): mixed
    {
        $elozo = $this->ceg;
        $this->ceg = $ceg;

        try {
            return $muvelet();
        } finally {
            $this->ceg = $elozo;
        }
    }
}
