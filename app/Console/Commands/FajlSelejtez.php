<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CsendesCron;
use App\Enums\DokumentumAllapot;
use App\Models\Company;
use App\Models\Document;
use App\Services\Files\FajlTarolo;
use App\Services\Ingest\PostafiokOlvaso;
use Illuminate\Console\Command;

/**
 * A megőrzési idejét letöltött eredeti bizonylatok törlése.
 *
 * Ha a cég nem kért türelmi időt, a fájl már az exportkor eltűnt — ez a parancs
 * azoknak van, akik kértek, és annak, ami valamiért kimaradt.
 *
 * A postafiók takarítása is itt van, és nem a `email:beolvas`-ban. Egyrészt ez
 * a megőrzési idő helye, másrészt a beolvasás öt percenként fut: dátum szerint
 * keresgélni ilyen sűrűn fölösleges terhelés. Ha ez kimaradna, a törölt fájl
 * egy másolata ott maradna a levél mellékletében — vagyis nem törölnénk
 * semmit, csak azt hinnénk.
 */
final class FajlSelejtez extends Command
{
    use CsendesCron;

    protected $signature = 'fajl:selejtez';

    protected $description = 'Törli az exportált iratok eredeti fájljait és a régi leveleket a megőrzési idő letelte után.';

    public function handle(FajlTarolo $tarolo, PostafiokOlvaso $olvaso): int
    {
        $osszes = 0;

        foreach (Company::query()->cursor() as $ceg) {
            $napok = $ceg->megorzesiNapok();

            $dokumentumok = Document::query()
                ->withoutGlobalScopes()
                ->where('company_id', $ceg->id)
                ->where('status', DokumentumAllapot::Exportalva->value)
                ->whereNotNull('storage_path')
                ->whereNull('file_deleted_at')
                ->where('updated_at', '<=', now()->subDays($napok))
                ->cursor();

            foreach ($dokumentumok as $dokumentum) {
                $tarolo->torol($dokumentum);
                $osszes++;
            }
        }

        $this->osszegzes("Törölve: {$osszes} eredeti fájl.");

        $this->postafiokot($olvaso);

        return self::SUCCESS;
    }

    /**
     * A postafiók takarítása nem állíthatja meg a parancsot: a fájlok törlése
     * ettől függetlenül megtörtént, és egy be sem állított IMAP (fejlesztői
     * gépen ez a szokásos) nem hiba, csak nincs mit takarítani.
     */
    private function postafiokot(PostafiokOlvaso $olvaso): void
    {
        // Ha nincs beérkeztető postafiók, nincs mit takarítani — ez beállítás,
        // nem hiba, tehát cronban nem szólal meg.
        if (! PostafiokOlvaso::beallitva()) {
            $this->megjegyzes('  <fg=gray>Nincs beérkeztető postafiók, nincs mit takarítani.</>');

            return;
        }

        try {
            $eredmeny = $olvaso->takarit();
        } catch (\Throwable $e) {
            $this->warn('A postafiók nem takarítható: '.$e->getMessage());

            return;
        }

        if ($eredmeny === []) {
            $this->megjegyzes('  <fg=gray>A postafiók takarítása ki van kapcsolva.</>');

            return;
        }

        foreach ($eredmeny as $mappa => $darab) {
            $this->osszegzes(sprintf('Törölve: %d levél a(z) „%s" mappából.', $darab, $mappa));
        }
    }
}
