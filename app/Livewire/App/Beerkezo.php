<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\DokumentumAllapot;
use App\Livewire\Concerns\Jogosultsag;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Services\Billing\Kvota;
use App\Services\Extraction\Sorkezelo;
use App\Services\Files\FajlHiba;
use App\Services\Files\FajlTarolo;
use App\Support\Berlo;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Beerkezo extends Component
{
    use Jogosultsag, WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $fajlok = [];

    public array $feltoltesiHibak = [];

    public function updatedFajlok(): void
    {
        $this->feltoltes();
    }

    public function feltoltes(): void
    {
        $this->kellSzerkeszto();

        $ceg = app(Berlo::class)->kotelezo();
        $tarolo = app(FajlTarolo::class);
        $this->feltoltesiHibak = [];
        $darab = 0;

        foreach ($this->fajlok as $fajl) {
            try {
                $tarolo->tarol(
                    $ceg,
                    (string) file_get_contents($fajl->getRealPath()),
                    $fajl->getClientOriginalName(),
                    $fajl->getMimeType(),
                    'upload',
                    auth()->id(),
                );
                $darab++;
            } catch (FajlHiba $e) {
                $this->feltoltesiHibak[] = $fajl->getClientOriginalName().': '.$e->getMessage();
            }
        }

        $this->fajlok = [];

        if ($darab > 0) {
            // Az első kiolvasás azonnal induljon, ne csak a következő
            // lekérdezéskor: a felhasználó így látja, hogy történik valami.
            $this->lepteti();
        }
    }

    /**
     * Egy adag munka. A Beérkező ezt hívja néhány másodpercenként, amíg van
     * feldolgozatlan irat — ez helyettesíti a háttér-workert, ami osztott
     * tárhelyen nincs.
     */
    public function lepteti(): void
    {
        $ceg = app(Berlo::class)->kotelezo();

        app(Sorkezelo::class)->egyet($ceg);
    }

    public function ujra(int $id): void
    {
        $this->kellSzerkeszto();

        $dokumentum = Document::query()->findOrFail($id);

        if ($dokumentum->status !== DokumentumAllapot::Hiba) {
            return;
        }

        $dokumentum->update([
            'status' => DokumentumAllapot::Feltoltve->value,
            'attempts' => 0,
            'error' => null,
        ]);
    }

    public function torol(int $id): void
    {
        $this->kellSzerkeszto();

        $dokumentum = Document::query()->findOrFail($id);

        // Jóváhagyott vagy exportált iratot innen nem lehet törölni: annak az
        // Archívum a helye, ahol a törlés külön, tudatos lépés.
        if (! in_array($dokumentum->status, [
            DokumentumAllapot::Feltoltve,
            DokumentumAllapot::EllenorzesreVar,
            DokumentumAllapot::Hiba,
            DokumentumAllapot::Duplikatum,
        ], true)) {
            return;
        }

        app(FajlTarolo::class)->torol($dokumentum);
        ActivityLog::rogzit('dokumentum.torolve', $dokumentum, $dokumentum->megnevezes());
        $dokumentum->delete();
    }

    public function render()
    {
        $ceg = app(Berlo::class)->kotelezo();
        $kvota = new Kvota($ceg);

        $dokumentumok = Document::query()
            ->beerkezo()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('livewire.app.beerkezo', [
            'ceg' => $ceg,
            'dokumentumok' => $dokumentumok,
            'dolgozikMeg' => app(Sorkezelo::class)->varakozikMeg($ceg),
            'akadaly' => $kvota->akadaly(),
            'maradek' => $kvota->maradek(),
        ]);
    }
}
