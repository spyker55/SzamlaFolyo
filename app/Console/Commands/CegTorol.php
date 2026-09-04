<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Document;
use App\Models\Export;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Egy cég végleges törlése.
 *
 * A felületen nincs rá gomb, és ez szándékos: egy fiók egy céget kezel, tehát
 * ez nem hétköznapi művelet, hanem hibajavítás — véletlenül létrejött cégek
 * eltakarítása. Aminek nincs napi használata, annak ne legyen gombja.
 *
 * Ami viszont **muszáj**: hogy előbb kiírja, mit fog törölni. A cégre az összes
 * bizonylata, kiolvasása, exportja és naplója rá van kötve `cascadeOnDelete`
 * módon — egy elgépelt név itt egy teljes ügyfélarchívumot vinne el némán.
 */
final class CegTorol extends Command
{
    protected $signature = 'ceg:torol {nev : A cég pontos neve} {--force : Kérdés nélkül}';

    protected $description = 'Véglegesen töröl egy céget minden hozzá tartozó adattal.';

    public function handle(): int
    {
        $cegek = Company::query()->where('name', $this->argument('nev'))->get();

        if ($cegek->isEmpty()) {
            $this->error(sprintf('Nincs „%s" nevű cég.', $this->argument('nev')));
            $this->line('A meglévők:');

            foreach (Company::query()->orderBy('id')->get() as $ceg) {
                $this->line(sprintf('  #%d  %s', $ceg->id, $ceg->name));
            }

            return self::FAILURE;
        }

        if ($cegek->count() > 1) {
            $this->error('Több cég is ezen a néven fut, ezért nem törlünk semmit:');

            foreach ($cegek as $ceg) {
                $this->line(sprintf('  #%d  létrehozva: %s', $ceg->id, $ceg->created_at));
            }

            return self::FAILURE;
        }

        $ceg = $cegek->first();

        // Előbb nézd meg, mit törölsz. A számok a bérlőszűrő megkerülésével
        // jönnek: a parancs nem egy bejelentkezett felhasználó nevében fut.
        $iratok = Document::query()->withoutGlobalScopes()->where('company_id', $ceg->id)->count();
        $exportok = Export::query()->withoutGlobalScopes()->where('company_id', $ceg->id)->count();
        $tagok = $ceg->users()->count();

        $this->table(['Mező', 'Érték'], [
            ['Azonosító', $ceg->id],
            ['Név', $ceg->name],
            ['Adószám', $ceg->tax_number ?: '—'],
            ['Létrehozva', (string) $ceg->created_at],
            ['Előfizetés', $ceg->stripe_subscription_id ?: 'nincs'],
            ['Bizonylat', $iratok],
            ['Export', $exportok],
            ['Felhasználó', $tagok],
        ]);

        if ($ceg->stripe_subscription_id !== null) {
            $this->error('Ehhez a céghez Stripe-előfizetés tartozik. Előbb a Stripe-ban mondd le, '
                .'különben a törlés után is számláznánk.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            $iratok > 0
                ? sprintf('Ezzel %d bizonylat és minden hozzá tartozó adat VÉGLEG elvész. Biztos?', $iratok)
                : 'Törlöd ezt a céget?',
            false,
        )) {
            $this->info('Nem történt semmi.');

            return self::SUCCESS;
        }

        // A fájlok nem a `cascadeOnDelete` hatálya alatt vannak: azokat
        // magunknak kell elvinnünk, különben a tárhelyen maradnak, gazdátlanul.
        $mappa = 'iratok/'.$ceg->id;

        if (Storage::disk('local')->exists($mappa)) {
            Storage::disk('local')->deleteDirectory($mappa);
        }

        $ceg->delete();

        $this->info(sprintf('A(z) „%s" cég (#%d) törölve.', $ceg->name, $ceg->id));

        return self::SUCCESS;
    }
}
