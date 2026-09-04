<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Ingest\PostafiokOlvaso;
use Illuminate\Console\Command;

final class EmailBeolvas extends Command
{
    protected $signature = 'email:beolvas
        {--limit=25 : Legfeljebb ennyi levél egy futásban}
        {--proba : Csak megmutatja, mit lát a postafiókban — nem dolgoz fel és nem mozgat semmit}';

    protected $description = 'Kiolvassa a beérkeztető postafiókot, és iratot csinál a mellékletekből.';

    public function handle(PostafiokOlvaso $olvaso): int
    {
        return $this->option('proba')
            ? $this->proba($olvaso)
            : $this->beolvasas($olvaso);
    }

    private function beolvasas(PostafiokOlvaso $olvaso): int
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

    /**
     * A „nem érkezik meg a számla" bejelentés lefordítása konkrét okra.
     *
     * Öt különböző dolgot jelenthet, és a felületen egyik sem látszik: nem is
     * jött be levél, rossz mappát nézünk, a címzettben nincs token, a tokenhez
     * nincs cég, vagy a melléklet nem támogatott típus. Ez mind az ötöt
     * megkülönbözteti — módosítás nélkül, tehát akárhányszor futtatható.
     */
    private function proba(PostafiokOlvaso $olvaso): int
    {
        $this->line('');
        $this->line(sprintf(
            '  <options=bold>Beküldési cím alakja:</> %s',
            config('inbox.mode') === 'plus'
                ? (string) config('inbox.plus_address').' (a + után a token)'
                : '<token>@'.config('inbox.domain'),
        ));

        foreach (Company::query()->orderBy('id')->get(['name', 'inbox_token']) as $ceg) {
            $this->line(sprintf(
                '  <fg=gray>%s → %s</>',
                $ceg->name,
                config('inbox.mode') === 'plus'
                    ? (string) str_replace('@', '+'.$ceg->inbox_token.'@', (string) config('inbox.plus_address'))
                    : $ceg->inbox_token.'@'.config('inbox.domain'),
            ));
        }

        $this->line('');

        try {
            $jelentes = $olvaso->diagnosztika((int) $this->option('limit') > 10 ? 10 : (int) $this->option('limit'));
        } catch (\Throwable $e) {
            $this->error('  A postafiók nem érhető el: '.$e->getMessage());
            $this->line('');
            $this->line('  <fg=yellow>Ilyenkor az IMAP_HOST, IMAP_USERNAME és IMAP_PASSWORD a gyanús.</>');
            $this->line('');

            return self::FAILURE;
        }

        $this->line('  <options=bold>Mappák a fiókban</>');
        $this->line('  '.($jelentes['mappak'] === [] ? '—' : implode(', ', $jelentes['mappak'])));
        $this->line('');

        if (! $jelentes['talalt_mappa']) {
            $this->error(sprintf('  A(z) „%s" mappa nem létezik — az IMAP_FOLDER a hibás.', $jelentes['mappa']));
            $this->line('');

            return self::FAILURE;
        }

        if ($jelentes['levelek'] === []) {
            $this->warn(sprintf('  A(z) „%s" mappa üres.', $jelentes['mappa']));
            $this->line('');
            $this->line('  <fg=yellow>Ez azt jelenti, hogy a levél be sem jött a fiókba: a hiba a levelezésnél</>');
            $this->line('  <fg=yellow>van (MX-rekord, catch-all átirányítás, spam mappa), nem az alkalmazásban.</>');
            $this->line('  <fg=gray>Ha a cron már átmozgatta a leveleket, nézd meg a Feldolgozott mappát is:</>');
            $this->line('  <fg=gray>IMAP_FOLDER=Feldolgozott artisan email:beolvas --proba</>');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line(sprintf('  <options=bold>A(z) „%s" mappa legutóbbi levelei</>', $jelentes['mappa']));
        $this->line('');

        foreach ($jelentes['levelek'] as $level) {
            $this->line(sprintf('  <options=bold>%s</>', $level['targy'] !== '' ? $level['targy'] : '(nincs tárgy)'));
            $this->line(sprintf('    feladó:    %s', $level['felado']));
            $this->line(sprintf('    címzettek: %s', $level['cimzettek']));

            if ($level['token'] === null) {
                $this->line('    <fg=red>✗ a címzettek közt nincs érvényes beküldési token</>');
            } elseif ($level['ceg'] === null) {
                $this->line(sprintf('    <fg=red>✗ token: %s — ehhez nincs cég</>', $level['token']));
            } else {
                $this->line(sprintf('    <fg=green>✓ cég: %s</>', $level['ceg']));
            }

            $this->line(sprintf(
                '    melléklet: %s',
                $level['mellekletek'] === [] ? '<fg=red>nincs feldolgozható</>' : implode(', ', $level['mellekletek']),
            ));
            $this->line('');
        }

        return self::SUCCESS;
    }
}
