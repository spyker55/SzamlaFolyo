<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CsendesCron;
use App\Enums\DokumentumAllapot;
use App\Models\Company;
use App\Models\Document;
use App\Services\Extraction\Sorkezelo;
use Illuminate\Console\Command;

/**
 * A sor hajtása cronból. A böngésző csak addig dolgozik, amíg valaki nézi a
 * Beérkezőt — az e-mailben érkezett iratokat és az elakadt futásokat ez viszi
 * tovább.
 */
final class DokumentumFeldolgoz extends Command
{
    use CsendesCron;

    protected $signature = 'dokumentum:feldolgoz {--limit=5 : Legfeljebb ennyi dokumentum egy futásban}';

    protected $description = 'Kiolvassa a feldolgozásra váró dokumentumokat.';

    public function handle(Sorkezelo $sorkezelo): int
    {
        $keret = (int) $this->option('limit');
        $osszes = 0;

        $cegIdk = Document::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [DokumentumAllapot::Feltoltve->value, DokumentumAllapot::FeldolgozasAlatt->value])
            ->distinct()
            ->pluck('company_id');

        foreach ($cegIdk as $cegId) {
            if ($osszes >= $keret) {
                break;
            }

            $ceg = Company::query()->find($cegId);

            if ($ceg === null) {
                continue;
            }

            $osszes += $sorkezelo->tobbet($ceg, $keret - $osszes);
        }

        $this->osszegzes("Feldolgozva: {$osszes} dokumentum.");

        return self::SUCCESS;
    }
}
