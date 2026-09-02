<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DokumentumAllapot;
use App\Models\Company;
use App\Models\Document;
use App\Services\Files\FajlTarolo;
use Illuminate\Console\Command;

/**
 * A megőrzési idejét letöltött eredeti fájlok törlése.
 *
 * Ha a cég nem kért türelmi időt, a fájl már az exportkor eltűnt — ez a parancs
 * azoknak van, akik kértek, és annak, ami valamiért kimaradt.
 */
final class FajlSelejtez extends Command
{
    protected $signature = 'fajl:selejtez';

    protected $description = 'Törli az exportált iratok eredeti fájljait a megőrzési idő letelte után.';

    public function handle(FajlTarolo $tarolo): int
    {
        $osszes = 0;

        foreach (Company::query()->cursor() as $ceg) {
            $napok = (int) $ceg->file_retention_days;

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

        $this->info("Törölve: {$osszes} eredeti fájl.");

        return self::SUCCESS;
    }
}
