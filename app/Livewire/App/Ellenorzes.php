<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Enums\AfaKategoria;
use App\Enums\DokumentumAllapot;
use App\Enums\DokumentumTipus;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCorrection;
use App\Services\Documents\AthelyezesHiba;
use App\Services\Documents\CegAjanlas;
use App\Services\Documents\DokumentumAthelyezes;
use App\Services\Extraction\Konfidencia;
use App\Services\Extraction\Sema;
use App\Services\Extraction\Validatorok;
use App\Support\Adoszam;
use App\Support\AfaBontas;
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

    /**
     * Az ÁFA-bontás szerkesztés alatti alakja: soronként sztringek, mert az
     * ember úgy gépel, ahogy a papíron látja („1 270,50"), nem ahogy tárolni
     * fogjuk. Az értelmezés a `parseoltBontas()` dolga.
     *
     * @var array<int, array<string, string>>
     */
    public array $bontas = [];

    public string $megjegyzes = '';

    /** @var array<string, float> */
    public array $konfidencia = [];

    /**
     * A bukott ellenőrzések — **a képernyőn lévő értékekre**, nem a gépiekre.
     * A `render()` tölti újra minden körben; lásd az ottani indoklást.
     *
     * @var array<string, string>
     */
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

        foreach (AfaBontas::sorok($dokumentum->afa_bontas) as $sor) {
            $this->bontas[] = [
                'kulcs' => $sor['kulcs'] === '—' ? '' : $sor['kulcs'],
                'kategoria' => (string) $sor['kategoria'],
                'netto' => (string) $sor['netto'],
                'afa' => (string) $sor['afa'],
            ];
        }

        $kiolvasas = $dokumentum->utolsoKiolvasas();
        $this->konfidencia = (array) ($kiolvasas?->confidence['combined'] ?? []);
    }

    /** Új, üres bontássor. */
    public function sorHozzaad(): void
    {
        // Ugyanaz a felső határ, amit a gépi út is betart: az ember ne tudjon
        // olyat előállítani, amit a séma nem fogadna el.
        if (count($this->bontas) >= Sema::BONTAS_MAX_SOR) {
            return;
        }

        $this->bontas[] = ['kulcs' => '', 'kategoria' => '', 'netto' => '', 'afa' => ''];
    }

    public function sorTorol(int $index): void
    {
        unset($this->bontas[$index]);
        $this->bontas = array_values($this->bontas);
    }

    /** A mező állapota: 'nincs_adat' | 'biztos' | 'bizonytalan' | 'gyanus'. */
    public function sav(string $mezo): string
    {
        // A bukott ellenőrzés önmagában elég a pirosításhoz. Eddig ez a modell
        // magabiztosságán keresztül jutott ide (a validátor 0,3-ra húzta le),
        // ami fölösleges kerülőút: a determinisztikus jel a megbízhatóbb a
        // kettő közül, és nem függhet attól, nyilatkozott-e róla a modell.
        if (isset($this->validatorHibak[$mezo])) {
            return 'gyanus';
        }

        return Konfidencia::sav($this->konfidencia[$mezo] ?? null);
    }

    public function jovahagyas(): void
    {
        $mezok = $this->ellenorzottMezok();
        $bontas = $this->parseoltBontas();

        // A bontás hibái ugyanúgy megállítanak, mint a mezőké: csendben nullát
        // menteni rosszabb, mint visszakérdezni. A **bukott ellenőrzés**
        // viszont nem állít meg — a papír az emberé, nem a miénk.
        foreach ($bontas['hibak'] as $kulcs => $uzenet) {
            $this->addError("bontas.{$kulcs}", $uzenet);
        }

        if ($mezok === null || $bontas['hibak'] !== []) {
            return;
        }

        $mezok['afa_bontas'] = $bontas['sorok'] === [] ? null : $bontas['sorok'];

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

            // A bontás nincs a MEZOK között (a fenti ciklus skalárt feltételez),
            // a javítását viszont ugyanúgy el akarjuk tenni — egyetlen sorban,
            // mindkét oldalt json alakban. A laza összehasonlítás szándékos: a
            // `json_encode(27.0)` „27"-et ír, tehát a tárolt kulcs int-ként jön
            // vissza, és a szigorú egyezés minden jóváhagyáskor fantomjavítást
            // szülne.
            $gepiBontas = $gepi['afa_bontas'] ?? null;

            if ($gepiBontas != $mezok['afa_bontas']) {
                $javitas = new DocumentCorrection([
                    'document_id' => $this->dokumentum->id,
                    'extraction_id' => $kiolvasas?->id,
                    'field' => 'afa_bontas',
                    'machine_value' => self::bontasSzoveg($gepiBontas),
                    'human_value' => self::bontasSzoveg($mezok['afa_bontas']),
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
     * A szerkesztett bontás értelmezése — egy helyen a képernyő és a mentés
     * számára is, hogy a jelzés ne másról szóljon, mint ami mentődni fog.
     *
     * A teljesen üres sor némán kiesik: a törléshez ne kelljen gombot keresni.
     * Ami félig kitöltött, az viszont hiba — a kulcs és az adóalap nélküli sor
     * se nem könyvelhető, se nem ellenőrizhető (ugyanezt a két mezőt követeli
     * meg a gépi úton a `Sema::tisztitBontas()`).
     *
     * @return array{sorok: array<int, array<string, mixed>>, hibak: array<string, string>}
     */
    private function parseoltBontas(): array
    {
        $sorok = [];
        $hibak = [];

        foreach ($this->bontas as $i => $sor) {
            $nyers = array_map(fn ($ertek) => trim((string) $ertek), $sor);

            if (implode('', $nyers) === '') {
                continue;
            }

            $kulcs = AfaBontas::kulcsErtelmez($nyers['kulcs'] ?? '');
            $netto = Osszeg::ertelmez($nyers['netto'] ?? '');
            $afa = Osszeg::ertelmez($nyers['afa'] ?? '');

            if ($kulcs === null) {
                $hibak["{$i}.kulcs"] = 'Az ÁFA-kulcsot százalékban kell megadni.';
            }

            if (! $netto->ok || $netto->ertek === null) {
                $hibak["{$i}.netto"] = $netto->ok
                    ? 'Az adóalapot meg kell adni.'
                    : 'Ezt az összeget nem tudjuk értelmezni.';
            }

            if (! $afa->ok) {
                $hibak["{$i}.afa"] = 'Ezt az összeget nem tudjuk értelmezni.';
            }

            if ($kulcs === null || $netto->ertek === null || ! $afa->ok) {
                continue;
            }

            $sorok[] = [
                'kulcs' => $kulcs,
                'kategoria' => AfaKategoria::tryFrom($nyers['kategoria'] ?? '')?->value,
                'netto' => $netto->ertek,
                'afa' => $afa->ertek,
            ];
        }

        return ['sorok' => $sorok, 'hibak' => $hibak];
    }

    /** @param  array<int, array<string, mixed>>|null  $bontas */
    private static function bontasSzoveg(?array $bontas): ?string
    {
        return $bontas === null || $bontas === []
            ? null
            : (json_encode($bontas, JSON_UNESCAPED_UNICODE) ?: null);
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

        foreach (Sema::DATUM_MEZOK as $mezo) {
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

        foreach (Sema::OSSZEG_MEZOK as $mezo) {
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
        // A jelzés arról szóljon, ami a képernyőn van.
        //
        // Korábban a kiolvasás sorában tárolt verdiktet mutattuk — az viszont a
        // **gépi** értékekről szól. Amint az ember átír egy számot, az az
        // állítás elavul: a javított mező pirosan maradna, a frissen elrontott
        // meg tisztán. A validátorok tiszta függvények és a nyers emberi
        // bemenetet is bírják (az összeg az `Osszeg`-en, a dátum az `Ido`-n át
        // megy), ezért olcsóbb újrafuttatni, mint magyarázkodni.
        //
        // A tárolt gépi verdikt ettől érintetlen marad: az az audit-nyom,
        // abból derül ki utólag, mit hibázott a modell.
        $this->validatorHibak = Validatorok::bukottak(
            $this->mezok,
            $this->parseoltBontas()['sorok'],
            $this->dokumentum->company?->tax_number,
        );

        return view('livewire.app.ellenorzes', [
            'ajanlottCeg' => $this->ajanlottCeg(),
            'cimkek' => Sema::CIMKEK,
            'tipusok' => DokumentumTipus::opciok(),
            'kategoriak' => AfaKategoria::opciok(),
            'maxSor' => Sema::BONTAS_MAX_SOR,
            'hatravan' => Document::query()
                ->where('status', DokumentumAllapot::EllenorzesreVar->value)
                ->count(),
        ]);
    }

    /**
     * Melyik másik cégéhez tartozik ez az irat.
     *
     * Csak akkor kérdezzük meg, ha az „idegen bizonylat" jelzés **tényleg**
     * megszólalt: a hibás ellenőrző számjegy nem ok arra, hogy másik céget
     * ajánljunk, és egy helyén lévő iratnál a felajánlott áthelyezés maga
     * volna a hiba.
     */
    private function ajanlottCeg(): ?Company
    {
        $felhasznalo = auth()->user();
        $cegAdoszam = $this->dokumentum->company?->tax_number;

        if ($felhasznalo === null || ! Validatorok::idegenE($this->mezok, $cegAdoszam)) {
            return null;
        }

        return CegAjanlas::talal(
            vevoAdoszam: is_string($this->mezok['customer_tax_number'] ?? null) ? $this->mezok['customer_tax_number'] : null,
            szallitoAdoszam: is_string($this->mezok['supplier_tax_number'] ?? null) ? $this->mezok['supplier_tax_number'] : null,
            felhasznalo: $felhasznalo,
            kizartCegId: (int) $this->dokumentum->company_id,
        );
    }

    /**
     * Az irat átvitele a felismert cégbe.
     *
     * A célcéget **újraszámoljuk**, nem a kérésből vesszük: egy hamisított
     * paraméter különben tetszőleges cég azonosítóját hozhatná. A jogokat és
     * a többi feltételt a szolgáltatás őrzi, de a bemenetet sem adjuk a
     * böngésző kezébe.
     */
    public function athelyez(): void
    {
        $cel = $this->ajanlottCeg();
        $felhasznalo = auth()->user();

        if ($cel === null || $felhasznalo === null) {
            $this->addError('athelyezes', 'Ez az irat nem tartozik egyik másik cégedhez sem.');

            return;
        }

        try {
            app(DokumentumAthelyezes::class)->athelyez($this->dokumentum, $cel, $felhasznalo);
        } catch (AthelyezesHiba $hiba) {
            $this->addError('athelyezes', $hiba->getMessage());

            return;
        }

        // Az irat innentől a másik cégé: ezen a képernyőn a bérlőszűrő már nem
        // is engedné megnyitni. A Beérkezőbe megyünk, nem a másik céghez —
        // a cégváltás külön, tudatos lépés maradjon.
        session()->flash('siker', sprintf(
            'Az iratot átvittük a(z) „%s” céghez. A fejlécből tudsz átváltani rá.',
            $cel->name,
        ));

        $this->redirect(route('beerkezo', absolute: false), navigate: true);
    }
}
