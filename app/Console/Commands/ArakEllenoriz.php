<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\Ar;
use App\Services\Billing\ArKatalogus;
use Illuminate\Console\Command;

/**
 * Egyezik-e a kiírt ár azzal, amit a Stripe ténylegesen terhel.
 *
 * A felületen és az árlistán a `config/szamlafolyo.php` számai állnak; a
 * pénzt viszont a Stripe árai mozgatják. A kettő között semmi nem garantálja
 * az egyezést — ha valaki átírja az egyiket, a másik csendben hazudik, és ezt
 * a vevő a számláján veszi észre. Ez az egyetlen hely, ahol a kettő
 * összeér, ezért a telepítés után ezt le kell futtatni.
 *
 * A darabárnak ráadásul **egyszeri** („one time") árnak kell lennie: a
 * `tulhasznalat:elszamol` darabszámmal tesz fel belőle tételt a következő
 * számlára. Ismétlődő árként ugyanez külön előfizetést indítana.
 */
final class ArakEllenoriz extends Command
{
    protected $signature = 'arak:ellenoriz';

    protected $description = 'Összeveti a csomagok kiírt árait a Stripe-ban beállítottakkal.';

    /** Forintnál a Stripe a legkisebb egységben számol, és százzal oszthatót vár. */
    private const HUF_SZORZO = 100;

    public function handle(ArKatalogus $katalogus): int
    {
        $sorok = [];
        $hibas = false;

        foreach ((array) config('szamlafolyo.plans') as $kulcs => $csomag) {
            foreach ([
                ['price_id', 'ar_havi', 'havi díj', true],
                ['price_id_extra', 'extra_ft', 'darabár', false],
            ] as [$azonositoMezo, $arMezo, $cimke, $ismetlodoKell]) {
                $priceId = $csomag[$azonositoMezo] ?? null;
                $varhato = (int) $csomag[$arMezo] * self::HUF_SZORZO;
                $nev = sprintf('%s · %s', $csomag['nev'], $cimke);

                if (! is_string($priceId) || $priceId === '') {
                    $sorok[] = [$nev, '—', 'HIÁNYZIK', 'nincs árazonosító az .env-ben'];
                    $hibas = true;

                    continue;
                }

                $ar = $katalogus->ar($priceId);

                if ($ar === null) {
                    $sorok[] = [$nev, $priceId, 'NINCS MEG', 'a Stripe nem ismeri ezt az árazonosítót'];
                    $hibas = true;

                    continue;
                }

                $bajok = $this->bajok($ar, $varhato, $ismetlodoKell);

                $sorok[] = [
                    $nev,
                    $priceId,
                    $bajok === [] ? 'OK' : 'ELTÉR',
                    $bajok === [] ? number_format($varhato / self::HUF_SZORZO, 0, ',', ' ').' Ft' : implode('; ', $bajok),
                ];

                $hibas = $hibas || $bajok !== [];
            }
        }

        $this->table(['Csomag', 'Árazonosító', 'Állapot', 'Megjegyzés'], $sorok);

        if ($hibas) {
            $this->error('A kiírt árak és a Stripe beállításai eltérnek. Amíg ez így van, a felület mást ígér, mint amit terhelünk.');

            return self::FAILURE;
        }

        $this->info('Minden ár egyezik a konfigurációval.');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function bajok(Ar $ar, int $varhato, bool $ismetlodoKell): array
    {
        $bajok = [];

        if ($ar->penznem !== 'huf') {
            $bajok[] = sprintf('pénznem %s, nem huf', $ar->penznem);
        }

        if ($ar->egysegar !== $varhato) {
            $bajok[] = sprintf(
                'a Stripe %s Ft, a konfiguráció %s Ft',
                number_format($ar->egysegar / self::HUF_SZORZO, 0, ',', ' '),
                number_format($varhato / self::HUF_SZORZO, 0, ',', ' '),
            );
        }

        if ($ar->ismetlodo !== $ismetlodoKell) {
            $bajok[] = $ismetlodoKell
                ? 'egyszeri ár, pedig előfizetésnek ismétlődő kell'
                : 'ismétlődő ár, pedig a darabárnak egyszerinek kell lennie';
        }

        if (! $ar->aktiv) {
            $bajok[] = 'archivált ár, nem lehet vele fizetni';
        }

        return $bajok;
    }
}
