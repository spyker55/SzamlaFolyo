<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Document;
use App\Services\Export\Oszlopok;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A jóváhagyott, exportra váró tételek. Innen még vissza lehet küldeni egy
 * tételt javításra — az export után már nem.
 */
#[Layout('components.layouts.app')]
class Tetelek extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $kereses = '';

    #[Url(as: 'tipus')]
    public string $tipus = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function javitasra(int $id): void
    {
        $dokumentum = Document::query()
            ->where('status', DokumentumAllapot::Jovahagyva->value)
            ->whereNull('export_id')
            ->findOrFail($id);

        $dokumentum->update([
            'status' => DokumentumAllapot::EllenorzesreVar->value,
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $this->redirect(route('ellenorzes', $dokumentum, absolute: false), navigate: true);
    }

    public function render()
    {
        $alap = Document::query()->exportalhato();

        $szurt = (clone $alap)
            ->when($this->tipus !== '', fn ($q) => $q->where('doc_type', $this->tipus))
            ->when($this->kereses !== '', function ($q): void {
                $minta = '%'.str_replace('%', '\%', $this->kereses).'%';
                $q->where(function ($q) use ($minta): void {
                    $q->where('supplier_name', 'ilike', $minta)
                        ->orWhere('customer_name', 'ilike', $minta)
                        ->orWhere('doc_number', 'ilike', $minta);
                });
            });

        return view('livewire.app.tetelek', [
            'tetelek' => (clone $szurt)->orderByDesc('issue_date')->orderByDesc('id')->paginate(25),
            'osszesites' => Oszlopok::osszesites((clone $szurt)->get()),
            'tipusok' => DokumentumTipus::opciok(),
        ]);
    }
}
