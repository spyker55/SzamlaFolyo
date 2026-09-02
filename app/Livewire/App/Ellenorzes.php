<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Document;
use App\Models\DocumentCorrection;
use App\Services\Extraction\Konfidencia;
use App\Services\Extraction\Sema;
use App\Support\Adoszam;
use App\Support\Ido;
use App\Support\Osszeg;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Az ellenőrző képernyő. Ez dönti el, hogy a termék gyors-e: itt ül a
 * felhasználó, és itt telik el az ideje.
 *
 * Ami az adatmodellből ide látszik: a `document` oszlopai az **ember
 * munkapéldánya**, a gépi érték a kiolvasás sorában marad. Jóváhagyáskor a
 * kettő különbsége mezőnként `document_corrections`-be kerül.
 */
#[Layout('components.layouts.app')]
class Ellenorzes extends Component
{
    public Document $dokumentum;

    /** @var array<string, mixed> */
    public array $mezok = [];

    public string $megjegyzes = '';

    /** @var array<string, float> */
    public array $konfidencia = [];

    /** @var array<string, string> */
    public array $validatorHibak = [];

    public function mount(Document $dokumentum): void
    {
        abort_unless(in_array($dokumentum->status, [
            DokumentumAllapot::EllenorzesreVar,
            DokumentumAllapot::Jovahagyva,
        ], true), 404);

        $this->dokumentum = $dokumentum;
        $this->megjegyzes = (string) $dokumentum->note;

        foreach (Sema::MEZOK as $mezo) {
            $ertek = $dokumentum->{$mezo};
            $this->mezok[$mezo] = match (true) {
                $ertek instanceof DokumentumTipus => $ertek->value,
                $ertek instanceof \DateTimeInterface => $ertek->format('Y-m-d'),
                default => $ertek === null ? '' : (string) $ertek,
            };
        }

        $kiolvasas = $dokumentum->utolsoKiolvasas();
        $this->konfidencia = (array) ($kiolvasas?->confidence['combined'] ?? []);
        $this->validatorHibak = (array) ($kiolvasas?->confidence['validators'] ?? []);
    }

    /** A mező színe: 'biztos' | 'bizonytalan' | 'gyanus'. */
    public function sav(string $mezo): string
    {
        return Konfidencia::sav($this->konfidencia[$mezo] ?? null);
    }

    public function jovahagyas(): void
    {
        $mezok = $this->ellenorzottMezok();

        if ($mezok === null) {
            return;
        }

        $kovetkezoId = $this->kovetkezoId();

        DB::transaction(function () use ($mezok): void {
            $kiolvasas = $this->dokumentum->utolsoKiolvasas();
            $gepi = (array) ($kiolvasas?->fields ?? []);

            // Amit az ember átírt, mezőnként eltesszük. Ebből derül ki idővel,
            // hol téved rendszeresen a modell — és ez az adat marad meg akkor
            // is, ha a promptot lecseréljük.
            foreach (Sema::MEZOK as $mezo) {
                $gepiErtek = $gepi[$mezo] ?? null;
                $emberi = $mezok[$mezo];

                if ((string) $gepiErtek === (string) $emberi) {
                    continue;
                }

                $javitas = new DocumentCorrection([
                    'document_id' => $this->dokumentum->id,
                    'extraction_id' => $kiolvasas?->id,
                    'field' => $mezo,
                    'machine_value' => $gepiErtek === null ? null : (string) $gepiErtek,
                    'human_value' => $emberi === null ? null : (string) $emberi,
                    'corrected_by' => auth()->id(),
                ]);
                $javitas->company_id = $this->dokumentum->company_id;
                $javitas->save();
            }

            $this->dokumentum->forceFill($mezok + [
                'note' => $this->megjegyzes !== '' ? $this->megjegyzes : null,
                'status' => DokumentumAllapot::Jovahagyva->value,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();
        });

        if ($kovetkezoId !== null) {
            $this->redirect(route('ellenorzes', $kovetkezoId, absolute: false), navigate: true);

            return;
        }

        session()->flash('siker', 'Kész: minden beérkezett irat ellenőrizve. A jóváhagyott tételek a Tételek képernyőn várnak.');
        $this->redirect(route('tetelek', absolute: false), navigate: true);
    }

    /**
     * A beírt értékek ellenőrzése és a mi alakunkra hozása. Ha valamit nem
     * értünk, itt megállunk: rosszabb csendben nullát menteni, mint
     * visszakérdezni.
     *
     * @return array<string, mixed>|null
     */
    private function ellenorzottMezok(): ?array
    {
        $this->resetErrorBag();
        $eredmeny = [];

        foreach (Sema::MEZOK as $mezo) {
            $ertek = trim((string) ($this->mezok[$mezo] ?? ''));
            $eredmeny[$mezo] = $ertek === '' ? null : $ertek;
        }

        if ($eredmeny['doc_type'] === null) {
            $this->addError('mezok.doc_type', 'A bizonylat típusát meg kell adni.');
        } elseif (DokumentumTipus::tryFrom((string) $eredmeny['doc_type']) === null) {
            $this->addError('mezok.doc_type', 'Ismeretlen bizonylattípus.');
        }

        foreach (['issue_date', 'fulfillment_date', 'due_date'] as $mezo) {
            if ($eredmeny[$mezo] === null) {
                continue;
            }

            $datum = Ido::datumErtelmez((string) $eredmeny[$mezo]);

            if ($datum === null) {
                $this->addError("mezok.{$mezo}", 'Ez nem értelmezhető dátum (ÉÉÉÉ-HH-NN).');

                continue;
            }

            $eredmeny[$mezo] = $datum;
        }

        foreach (['net_amount', 'vat_amount', 'gross_amount'] as $mezo) {
            if ($eredmeny[$mezo] === null) {
                continue;
            }

            $osszeg = Osszeg::ertelmez((string) $eredmeny[$mezo]);

            if (! $osszeg->ok) {
                $this->addError("mezok.{$mezo}", 'Ezt az összeget nem tudjuk értelmezni.');

                continue;
            }

            $eredmeny[$mezo] = $osszeg->ertek;
        }

        if ($eredmeny['currency'] !== null) {
            $eredmeny['currency'] = strtoupper(substr((string) $eredmeny['currency'], 0, 3));
        }

        foreach (['supplier_tax_number', 'customer_tax_number'] as $mezo) {
            if ($eredmeny[$mezo] !== null) {
                $eredmeny[$mezo] = Adoszam::formaz((string) $eredmeny[$mezo]);
            }
        }

        return $this->getErrorBag()->isEmpty() ? $eredmeny : null;
    }

    /** A következő ellenőrzésre váró irat — enélkül minden jóváhagyás után listázni kellene. */
    private function kovetkezoId(): ?int
    {
        return Document::query()
            ->where('status', DokumentumAllapot::EllenorzesreVar->value)
            ->whereKeyNot($this->dokumentum->id)
            ->orderBy('id')
            ->value('id');
    }

    public function render()
    {
        return view('livewire.app.ellenorzes', [
            'cimkek' => Sema::CIMKEK,
            'tipusok' => DokumentumTipus::opciok(),
            'hatravan' => Document::query()
                ->where('status', DokumentumAllapot::EllenorzesreVar->value)
                ->count(),
        ]);
    }
}
