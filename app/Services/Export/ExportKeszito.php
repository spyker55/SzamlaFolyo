<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\DokumentumAllapot;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\Export;
use App\Services\Files\FajlTarolo;
use App\Support\Ido;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Az export elkészítése — és az egyetlen hely, ahol fájl végleg törlődik.
 *
 * A sorrend kötött, mert visszafordíthatatlan lépés van benne:
 *   1. az export fájl elkészül,
 *   2. a tételek megkapják az `export_id`-t,
 *   3. és **csak ezután** töröljük az eredeti bizonylatokat.
 *
 * Ha a 2. lépés elhasal, a fájlok megmaradnak. Fordított sorrendben egy
 * félbemaradt export után a bizonylat is odalenne, és az adat is.
 */
final class ExportKeszito
{
    public const LEMEZ = 'local';

    public function __construct(private readonly FajlTarolo $tarolo) {}

    /**
     * @param  Collection<int, Document>  $dokumentumok
     */
    public function keszit(Company $ceg, Collection $dokumentumok, string $formatum, array $szurok = []): Export
    {
        if ($dokumentumok->isEmpty()) {
            throw new RuntimeException('Nincs exportálható tétel.');
        }

        if (! in_array($formatum, ['xlsx', 'csv', 'json'], true)) {
            throw new RuntimeException('Ismeretlen exportformátum.');
        }

        $sorok = $dokumentumok->map(fn (Document $d): array => Oszlopok::sor($d));
        $fajlnev = $this->fajlnev($ceg, $formatum);
        $utvonal = sprintf('exportok/%d/%s-%s', $ceg->id, Str::random(8), $fajlnev);

        $tartalom = match ($formatum) {
            'csv' => CsvIro::ir($sorok),
            'json' => JsonIro::ir($sorok, [
                'ceg' => $ceg->name,
                'keszult' => Ido::most()->toIso8601String(),
                'darab' => $dokumentumok->count(),
                'osszesites' => Oszlopok::osszesites($dokumentumok),
            ]),
            'xlsx' => null,  // közvetlenül fájlba íródik
        };

        if ($formatum === 'xlsx') {
            // Az OpenSpout fájlba ír, ezért kell egy valódi útvonal a lemezen.
            $teljesUtvonal = Storage::disk(self::LEMEZ)->path($utvonal);
            @mkdir(dirname($teljesUtvonal), 0755, true);
            XlsxIro::fajlba($sorok, $teljesUtvonal);
        } else {
            Storage::disk(self::LEMEZ)->put($utvonal, (string) $tartalom);
        }

        $meret = (int) Storage::disk(self::LEMEZ)->size($utvonal);

        $export = DB::transaction(function () use ($ceg, $dokumentumok, $formatum, $szurok, $utvonal, $fajlnev, $meret): Export {
            $export = new Export([
                'format' => $formatum,
                'filters' => $szurok ?: null,
                'item_count' => $dokumentumok->count(),
                'file_path' => $utvonal,
                'file_name' => $fajlnev,
                'file_bytes' => $meret,
                'created_by' => auth()->id(),
            ]);
            $export->company_id = $ceg->id;
            $export->save();

            Document::query()
                ->withoutGlobalScopes()
                ->where('company_id', $ceg->id)
                ->whereIn('id', $dokumentumok->pluck('id'))
                ->update([
                    'export_id' => $export->id,
                    'status' => DokumentumAllapot::Exportalva->value,
                    'updated_at' => now(),
                ]);

            return $export;
        });

        ActivityLog::rogzit('export.keszult', $export, sprintf(
            '%d tétel · %s',
            $export->item_count,
            strtoupper($formatum),
        ), ['formatum' => $formatum]);

        $this->eredetiketTorol($ceg, $dokumentumok);

        return $export;
    }

    /**
     * Az eredeti bizonylatok törlése. A cég beállíthat türelmi időt; alapból
     * nincs, mert a tárhely véges — de a felhasználó előtte le tudja tölteni
     * őket egy ZIP-ben, és ezt a képernyő ki is mondja.
     */
    private function eredetiketTorol(Company $ceg, Collection $dokumentumok): void
    {
        if ($ceg->megorzesiNapok() > 0) {
            return;   // a fajl:selejtez parancs viszi el, ha letelt az idő
        }

        foreach ($dokumentumok as $dokumentum) {
            if ($dokumentum->vanFajlja()) {
                $this->tarolo->torol($dokumentum);
            }
        }

        ActivityLog::rogzit('fajl.torolve', null, sprintf(
            '%d eredeti bizonylat törölve export után',
            $dokumentumok->count(),
        ));
    }

    /**
     * A ZIP az eredeti bizonylatokkal — ezt tölti le a felhasználó, mielőtt a
     * szerverről törlődnének. Tömörítés nélkül (STORE): a PDF és a JPEG már
     * tömörített, a deflate csak CPU-t égetne.
     *
     * @param  Collection<int, Document>  $dokumentumok
     */
    public function eredetikZip(Collection $dokumentumok): string
    {
        $ideiglenes = tempnam(sys_get_temp_dir(), 'szf');
        $zip = new \ZipArchive;

        if ($zip->open($ideiglenes, \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('A ZIP nem hozható létre.');
        }

        $hasznaltNevek = [];

        foreach ($dokumentumok as $dokumentum) {
            $tartalom = $this->tarolo->tartalom($dokumentum);

            if ($tartalom === null) {
                continue;
            }

            $nev = $this->egyediNev($dokumentum, $hasznaltNevek);
            $zip->addFromString($nev, $tartalom);
            $zip->setCompressionName($nev, \ZipArchive::CM_STORE);
        }

        $zip->close();

        return $ideiglenes;
    }

    private function egyediNev(Document $dokumentum, array &$hasznalt): string
    {
        $alap = Str::of($dokumentum->doc_number ?: 'irat-'.$dokumentum->id)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-')
            ->limit(60, '')
            ->value();

        $kiterjesztes = pathinfo((string) $dokumentum->original_filename, PATHINFO_EXTENSION) ?: 'pdf';
        $nev = $alap.'.'.$kiterjesztes;
        $n = 2;

        while (isset($hasznalt[$nev])) {
            $nev = $alap.'_'.$n.'.'.$kiterjesztes;
            $n++;
        }

        $hasznalt[$nev] = true;

        return $nev;
    }

    private function fajlnev(Company $ceg, string $formatum): string
    {
        $ceginev = Str::of($ceg->name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->limit(40, '')->value();

        return sprintf('szamlafolyo-%s-%s.%s', $ceginev ?: 'export', Ido::most()->format('Y-m-d-Hi'), $formatum);
    }
}
