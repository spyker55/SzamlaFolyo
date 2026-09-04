<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\DokumentumTipus;
use App\Models\Document;
use App\Services\Export\ExportKeszito;
use App\Services\Export\Oszlopok;
use App\Support\Adoszam;
use App\Support\Berlo;
use App\Support\Ido;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * Az ügyfél törzsszáma, akire szűkítünk — üresen mind.
     *
     * **Törzsszám, nem teljes adószám.** Ugyanaz a cég szerepelhet
     * „12345678-2-41" és „HU12345678" alakban is ugyanabban a hónapban; az
     * adóalanyt az első nyolc jegy azonosítja, az ÁFA-kód és a megyekód
     * változhat. Névre szűrni még rosszabb volna: a „Példa Kft.",
     * „Példa Kft" és „PÉLDA KFT" ugyanaz a cég, három sztring.
     */
    public string $ugyfel = '';

    public bool $eredetikLetoltve = false;

    public function mount(): void
    {
        $this->tolDatum = Ido::most()->startOfMonth()->toDateString();
        $this->igDatum = Ido::most()->endOfMonth()->toDateString();
    }

    /**
     * Az eredeti bizonylatok ZIP-ben.
     *
     * A visszatérési típus **`BinaryFileResponse`**, és ez nem részletkérdés: a
     * `Response::download()` ilyet ad, a korábbi `?StreamedResponse` deklaráció
     * viszont nem rokona ennek, tehát a metódus típushibával szállt el, mielőtt
     * bármi letöltődött volna. A böngésző ebből csak annyit mutatott, hogy
     * elromlott valami — a gomb pedig soha nem működött.
     */
    public function eredetikZip(): ?BinaryFileResponse
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

    public function exportal(): void
    {
        $ceg = app(Berlo::class)->kotelezo();
        $dokumentumok = $this->dokumentumok();

        if ($dokumentumok->isEmpty()) {
            $this->addError('export', 'Ebben az időszakban nincs exportálható tétel.');

            return;
        }

        $export = app(ExportKeszito::class)->keszit($ceg, $dokumentumok, $this->formatum, [
            'tol' => $this->tolDatum,
            'ig' => $this->igDatum,
            'tipus' => $this->tipus ?: null,
            'ugyfel' => $this->ugyfel ?: null,
        ]);

        session()->flash('siker', sprintf(
            '%d tétel exportálva. %s',
            $export->item_count,
            $ceg->megorzesiNapok() > 0
                ? sprintf('Az eredeti fájlok %d nap múlva törlődnek.', $ceg->megorzesiNapok())
                : 'Az eredeti fájlok törlődtek a szerverről.',
        ));

        $this->redirect(route('archivum', absolute: false), navigate: true);
    }

    /** @return Collection<int, Document> */
    private function dokumentumok()
    {
        $sorok = $this->idoszakSorai();

        if ($this->ugyfel === '') {
            return $sorok;
        }

        return $sorok->filter(fn (Document $d): bool => self::ugyfele($d, $this->ugyfel))->values();
    }

    /** A szűrés előtti halmaz: ebből áll össze az ügyféllista is. */
    private function idoszakSorai(): Collection
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

    /**
     * Ehhez az ügyfélhez tartozik-e a bizonylat.
     *
     * **Mindkét oldalt nézzük.** A könyvelő ügyfele a bejövő számlán a vevő, a
     * kimenőn viszont a szállító — ugyanannak az ügyfélnek a papírjai. Aki az
     * ügyfelét választja ki, mindkettőt várja, nem a felét.
     *
     * A szűrés a lekérdezés **után**, PHP-ben történik: a tárolt adószám
     * formátuma bizonylatonként más lehet, a törzsszámot pedig csak
     * normalizálás után lehet összevetni. Az időszak sorai amúgy is a memóriába
     * kerülnek (az összesítés és az export is ugyanezt a halmazt kapja), tehát
     * ez nem plusz lekérdezés.
     */
    private static function ugyfele(Document $dokumentum, string $torzsszam): bool
    {
        return Adoszam::torzsszam($dokumentum->customer_tax_number) === $torzsszam
            || Adoszam::torzsszam($dokumentum->supplier_tax_number) === $torzsszam;
    }

    /**
     * A választható ügyfelek: a **vevő** oldal törzsszámai az időszakban.
     *
     * Csak a vevő oldaláról gyűjtünk, mert a könyvelő ügyfelei ott állnak
     * ismétlődően; a szállítók listája minden beszállítót tartalmazna, és
     * használhatatlanul hosszú lenne. A kiválasztott ügyfél kimenő számlái
     * ettől még bejönnek — azt az `ugyfele()` intézi.
     *
     * @return array<string, string> törzsszám => „Név (adószám)"
     */
    private function ugyfelek(): array
    {
        $ki = [];

        foreach ($this->idoszakSorai() as $dokumentum) {
            $torzsszam = Adoszam::torzsszam($dokumentum->customer_tax_number);

            if ($torzsszam === null || isset($ki[$torzsszam])) {
                continue;
            }

            $nev = trim((string) $dokumentum->customer_name);
            $ki[$torzsszam] = $nev === ''
                ? (string) $dokumentum->customer_tax_number
                : sprintf('%s (%s)', $nev, $dokumentum->customer_tax_number);
        }

        asort($ki);

        return $ki;
    }

    public function render()
    {
        $dokumentumok = $this->dokumentumok();

        return view('livewire.app.export', [
            'darab' => $dokumentumok->count(),
            'osszesites' => Oszlopok::osszesites($dokumentumok),
            'tipusok' => DokumentumTipus::opciok(),
            'ugyfelek' => $this->ugyfelek(),
            'ceg' => app(Berlo::class)->kotelezo(),
        ]);
    }
}
