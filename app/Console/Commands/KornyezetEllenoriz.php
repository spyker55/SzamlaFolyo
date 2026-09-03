<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * „Megvan-e minden, ami ehhez kell?"
 *
 * Osztott tárhelyen a környezet nem a miénk: a PHP verziója webcímenként más
 * lehet, mint SSH-n, egy kiterjesztés hiányozhat, az adatbázis szervere pedig
 * más verzió, mint a `psql` kliensé. Ez a parancs egyszer lefut, és kimondja,
 * mi hiányzik — a hibát ne az első elveszett bizonylat jelezze.
 */
final class KornyezetEllenoriz extends Command
{
    protected $signature = 'kornyezet:ellenoriz';

    protected $description = 'Ellenőrzi, hogy a futtatókörnyezet alkalmas-e az alkalmazás futtatására.';

    private const MIN_PHP = '8.2';

    /** Ezek nélkül az alkalmazás nem működik. */
    private const KOTELEZO_KITERJESZTESEK = [
        'pdo_pgsql' => 'adatbázis',
        'mbstring' => 'szövegkezelés',
        'openssl' => 'titkosítás, HTTPS',
        'curl' => 'OpenRouter- és Stripe-hívás',
        'fileinfo' => 'a feltöltött fájl valódi típusa',
        'zip' => 'eredeti bizonylatok ZIP-je',
        'xmlreader' => 'xlsx export (openspout)',
        'intl' => 'magyar szöveg- és számformázás',
    ];

    /** Ezek hiánya nem állítja meg a rendszert, de érdemes tudni róla. */
    private const AJANLOTT_KITERJESZTESEK = [
        'gd' => 'képfeldolgozás',
        // Az OPcache-t nem a nevével kérdezzük: a kiterjesztés „Zend OPcache"
        // néven regisztrálja magát, így az extension_loaded('opcache') hamisan
        // hiányzónak mutatná.
        'Zend OPcache' => 'sebesség',
    ];

    private int $hibak = 0;

    private int $figyelmeztetesek = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>SzámlaFolyó — környezet-ellenőrzés</>');
        $this->line('');

        $this->phpVerzio();
        $this->kiterjesztesek();
        $this->adatbazis();
        $this->konyvtarak();
        $this->beallitasok();

        $this->line('');

        if ($this->hibak > 0) {
            $this->line(sprintf(
                '  <fg=red;options=bold>%d hiba</> · %d figyelmeztetés — így az alkalmazás nem fog működni.',
                $this->hibak,
                $this->figyelmeztetesek,
            ));
            $this->line('');

            return self::FAILURE;
        }

        $this->line($this->figyelmeztetesek > 0
            ? sprintf('  <fg=green;options=bold>Rendben</> — %d figyelmeztetéssel.', $this->figyelmeztetesek)
            : '  <fg=green;options=bold>Minden rendben.</>');
        $this->line('');

        return self::SUCCESS;
    }

    private function phpVerzio(): void
    {
        $verzio = PHP_VERSION;

        if (version_compare($verzio, self::MIN_PHP, '>=')) {
            $this->ok("PHP {$verzio}");

            return;
        }

        $this->hiba(
            "PHP {$verzio} — legalább ".self::MIN_PHP.' kell',
            'Ezen a tárhelyen az SSH alapértelmezett PHP-je régebbi, mint a webcímé. '
            .'Add meg a helyeset: PHP_BIN=/eleresi/ut/php ./deploy.sh',
        );
    }

    private function kiterjesztesek(): void
    {
        $hianyzo = [];

        foreach (self::KOTELEZO_KITERJESZTESEK as $nev => $mire) {
            if (! extension_loaded($nev)) {
                $hianyzo[] = "{$nev} ({$mire})";
            }
        }

        if ($hianyzo === []) {
            $this->ok('Kiterjesztések megvannak ('.count(self::KOTELEZO_KITERJESZTESEK).' kötelező)');
        } else {
            $this->hiba(
                'Hiányzó kiterjesztés: '.implode(', ', $hianyzo),
                'A nethely admin felületén a „PHP beállítások" alatt kapcsolhatók be.',
            );
        }

        foreach (self::AJANLOTT_KITERJESZTESEK as $nev => $mire) {
            if (! extension_loaded($nev)) {
                $this->figyelmeztetes("Nincs {$nev} ({$mire}) — működik nélküle, csak lassabb vagy szűkebb.");
            }
        }
    }

    private function adatbazis(): void
    {
        try {
            // A `psql --version` csak a klienst mondja meg; minket a szerver érdekel.
            $verzio = (string) DB::selectOne('select version() as v')->v;
            $szam = preg_match('/PostgreSQL (\d+(?:\.\d+)?)/', $verzio, $m) === 1 ? $m[1] : null;

            $this->ok('Adatbázis elérhető'.($szam !== null ? " — PostgreSQL {$szam}" : ''));

            if ($szam !== null && version_compare($szam, '11', '<')) {
                $this->hiba(
                    "PostgreSQL {$szam} túl régi",
                    'A séma legalább 11-et kíván.',
                );
            } elseif ($szam !== null && version_compare($szam, '12', '<')) {
                // A Laravel séma-értelmezője kifejezetten kezeli a 12 alatti
                // verziót, és a mi migrációnk sem használ 12+ elemet — de a
                // PostgreSQL 11 lejárt támogatású, ezt ki kell mondani.
                $this->figyelmeztetes(
                    "PostgreSQL {$szam} — az alkalmazás elmegy rajta, de ez a verzió "
                    .'lejárt támogatású, biztonsági javítást nem kap. Érdemes rákérdezni a szolgáltatónál.',
                );
            }
        } catch (Throwable $e) {
            $this->hiba(
                'Az adatbázis nem érhető el: '.$e->getMessage(),
                'Ellenőrizd a DB_* értékeket a .env fájlban.',
            );
        }
    }

    private function konyvtarak(): void
    {
        foreach (['storage', 'bootstrap/cache', 'storage/app/private'] as $konyvtar) {
            $ut = base_path($konyvtar);

            if (! is_dir($ut)) {
                @mkdir($ut, 0755, true);
            }

            if (is_dir($ut) && is_writable($ut)) {
                continue;
            }

            $this->hiba("Nem írható: {$konyvtar}", 'chmod -R u+w '.$konyvtar);

            return;
        }

        $this->ok('A könyvtárak írhatók');

        if ((string) config('app.key') === '') {
            $this->hiba('Nincs APP_KEY', 'Futtasd: artisan key:generate');
        }
    }

    /**
     * A külső szolgáltatások hiánya nem hiba: a rendszer elindul nélkülük is,
     * csak az adott funkció nem megy. Ezt viszont jobb előre tudni, mint akkor,
     * amikor egy ügyfél bizonylata nem érkezik meg.
     */
    private function beallitasok(): void
    {
        $ellenorzesek = [
            'AI-kiolvasás' => (string) config('openrouter.api_key') !== '',
            'E-mailes beérkeztetés' => (string) config('inbox.imap.host') !== ''
                && (string) config('inbox.imap.username') !== '',
            'Stripe előfizetés' => (string) config('stripe.secret') !== '',
            'Kimenő levél (SMTP)' => (string) config('mail.mailers.smtp.host') !== '',
        ];

        foreach ($ellenorzesek as $mi => $beallitva) {
            if ($beallitva) {
                $this->ok("{$mi} beállítva");
            } else {
                $this->figyelmeztetes("{$mi} nincs beállítva — a funkció addig nem működik.");
            }
        }

        if (config('app.debug') === true && app()->environment('production')) {
            $this->hiba(
                'APP_DEBUG=true éles környezetben',
                'Hibánál kiírná a konfigurációt a látogatónak. Állítsd false-ra.',
            );
        }
    }

    private function ok(string $uzenet): void
    {
        $this->line("  <fg=green>✓</> {$uzenet}");
    }

    private function figyelmeztetes(string $uzenet): void
    {
        $this->figyelmeztetesek++;
        $this->line("  <fg=yellow>!</> {$uzenet}");
    }

    private function hiba(string $uzenet, ?string $teendo = null): void
    {
        $this->hibak++;
        $this->line("  <fg=red>✗</> {$uzenet}");

        if ($teendo !== null) {
            $this->line("    <fg=gray>→ {$teendo}</>");
        }
    }
}
