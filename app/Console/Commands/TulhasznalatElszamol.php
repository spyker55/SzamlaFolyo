<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\Tulhasznalat;
use Illuminate\Console\Command;

/**
 * A keret fölötti darabok felvitele a következő Stripe-számlára.
 *
 * Naponta fut. Nem baj, ha egy futás kimarad vagy megszakad: a szolgáltatás
 * mindig a ténylegesen túllépett és a már kiszámlázott kreditek különbségét
 * viszi fel, tehát a következő futás pótolja — kétszer terhelni viszont nem
 * tud.
 */
final class TulhasznalatElszamol extends Command
{
    protected $signature = 'tulhasznalat:elszamol';

    protected $description = 'A keret fölötti dokumentumokat felviszi a következő Stripe-számlára.';

    public function handle(Tulhasznalat $tulhasznalat): int
    {
        $eredmeny = $tulhasznalat->elszamol();

        $this->info(sprintf(
            '%d cég · %d kredit számlázva · %d hiba.',
            $eredmeny['cegek'],
            $eredmeny['kreditek'],
            $eredmeny['hibak'],
        ));

        return $eredmeny['hibak'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
