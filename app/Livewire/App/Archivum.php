<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\DokumentumAllapot;
use App\Livewire\Concerns\Uzenetek;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Export;
use App\Services\Files\FajlTarolo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Az archívum: ami már kiment. Egy tétel visszahívható a Tételek közé, vagy
 * véglegesen törölhető — mindkettő tudatos, naplózott lépés.
 */
#[Layout('components.layouts.app')]
class Archivum extends Component
{
    use Uzenetek;

    public ?int $nyitottExportId = null;

    public function nyit(int $id): void
    {
        $this->nyitottExportId = $this->nyitottExportId === $id ? null : $id;
    }

    public function visszahiv(int $dokumentumId): void
    {
        $dokumentum = Document::query()
            ->where('status', DokumentumAllapot::Exportalva->value)
            ->findOrFail($dokumentumId);

        $dokumentum->update([
            'status' => DokumentumAllapot::Jovahagyva->value,
            'export_id' => null,
        ]);

        ActivityLog::rogzit('tetel.visszahivva', $dokumentum, $dokumentum->megnevezes());

        $this->uzenet('A tétel visszakerült a Tételek közé, és újra exportálható.');
    }

    public function tetelTorles(int $dokumentumId): void
    {
        $dokumentum = Document::query()
            ->where('status', DokumentumAllapot::Exportalva->value)
            ->findOrFail($dokumentumId);

        app(FajlTarolo::class)->torol($dokumentum);
        ActivityLog::rogzit('tetel.veglegesen_torolve', $dokumentum, $dokumentum->megnevezes());
        $dokumentum->delete();
    }

    /**
     * Egy teljes export törlése. A tételek is mennek vele — az export
     * megléte a hivatkozás rájuk, enélkül gazdátlan sorok maradnának az
     * archívumban.
     */
    public function exportTorles(int $exportId): void
    {
        $export = Export::query()->findOrFail($exportId);

        DB::transaction(function () use ($export): void {
            $tarolo = app(FajlTarolo::class);

            foreach ($export->documents()->get() as $dokumentum) {
                $tarolo->torol($dokumentum);
                $dokumentum->delete();
            }

            if ($export->file_path !== null) {
                Storage::disk('local')->delete($export->file_path);
            }

            ActivityLog::rogzit('export.torolve', $export, $export->file_name, [
                'darab' => $export->item_count,
            ]);

            $export->delete();
        });

        $this->nyitottExportId = null;
        $this->uzenet('Az export és a hozzá tartozó tételek véglegesen törölve.');
    }

    public function render()
    {
        $exportok = Export::query()->withCount('documents')->orderByDesc('id')->paginate(20);

        $tetelek = $this->nyitottExportId !== null
            ? Document::query()->where('export_id', $this->nyitottExportId)->orderBy('issue_date')->get()
            : collect();

        return view('livewire.app.archivum', [
            'exportok' => $exportok,
            'tetelek' => $tetelek,
        ]);
    }
}
