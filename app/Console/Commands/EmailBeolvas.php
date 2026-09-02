<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ingest\PostafiokOlvaso;
use Illuminate\Console\Command;

final class EmailBeolvas extends Command
{
    protected $signature = 'email:beolvas {--limit=25 : Legfeljebb ennyi levél egy futásban}';

    protected $description = 'Kiolvassa a beérkeztető postafiókot, és iratot csinál a mellékletekből.';

    public function handle(PostafiokOlvaso $olvaso): int
    {
        try {
            $eredmeny = $olvaso->olvas((int) $this->option('limit'));
        } catch (\Throwable $e) {
            $this->error('A postafiók nem olvasható: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d levél · %d irat · %d átugorva.',
            $eredmeny['levelek'],
            $eredmeny['iratok'],
            $eredmeny['atugrott'],
        ));

        return self::SUCCESS;
    }
}
