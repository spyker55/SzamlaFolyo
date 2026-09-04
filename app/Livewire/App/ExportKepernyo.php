<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\DokumentumTipus;
use App\Models\Document;
use App\Services\Export\ExportKeszito;
use App\Services\Export\Oszlopok;
use App\Support\Berlo;
use App\Support\Ido;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Az export. A képernyő sorrendje szándékos: előbb látod, mi megy ki, aztán
 * letöltheted az eredetiket, és csak utána készül el az export — mert az
 * elkészültével a bizonylatok fájljai törlődnek a szerverről.
 */
#[Layout('components.layouts.app')]
class ExportKepernyo extends Component
{
    public string $formatum = 'xlsx';

    public string $tolDatum = '';

    public string $igDatum = '';

    public string $tipus = '';

    public bool $eredetikLetoltve = false;

    public function mount(): void
    {
        $this->tolDatum = Ido::most()->startOfMonth()->toDateString();
        $this->igDatum = Ido::most()->endOfMonth()->toDateString();
    }

    public function eredetikZip(): ?StreamedResponse
    {
        $dokumentumok = $this->dokumentumok();

        if ($dokumentumok->isEmpty()) {
            return null;
        }

        $utvonal = app(ExportKeszito::class)->eredetikZip($dokumentumok);
        $this->eredetikLetoltve = true;

        return Response::download($utvonal, 'eredeti-bizonylatok-'.Ido::most()->format('Y-m-d').'.zip')
            ->deleteFileAfterSend();
    }

    public function exportal(): ?StreamedResponse
    {
        $ceg = app(Berlo::class)->kotelezo();
        $dokumentumok = $this->dokumentumok();

        if ($dokumentumok->isEmpty()) {
            $this->addError('export', 'Ebben az időszakban nincs exportálható tétel.');

            return null;
        }

        $export = app(ExportKeszito::class)->keszit($ceg, $dokumentumok, $this->formatum, [
            'tol' => $this->tolDatum,
            'ig' => $this->igDatum,
            'tipus' => $this->tipus ?: null,
        ]);

        session()->flash('siker', sprintf(
            '%d tétel exportálva. %s',
            $export->item_count,
            $ceg->megorzesiNapok() > 0
                ? sprintf('Az eredeti fájlok %d nap múlva törlődnek.', $ceg->megorzesiNapok())
                : 'Az eredeti fájlok törlődtek a szerverről.',
        ));

        $this->redirect(route('archivum', absolute: false), navigate: true);

        return null;
    }

    /** @return Collection<int, Document> */
    private function dokumentumok()
    {
        return Document::query()
            ->exportalhato()
            ->when($this->tipus !== '', fn ($q) => $q->where('doc_type', $this->tipus))
            ->when($this->tolDatum !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->tolDatum))
            ->when($this->igDatum !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->igDatum))
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();
    }

    public function render()
    {
        $dokumentumok = $this->dokumentumok();

        return view('livewire.app.export', [
            'darab' => $dokumentumok->count(),
            'osszesites' => Oszlopok::osszesites($dokumentumok),
            'tipusok' => DokumentumTipus::opciok(),
            'ceg' => app(Berlo::class)->kotelezo(),
        ]);
    }
}
