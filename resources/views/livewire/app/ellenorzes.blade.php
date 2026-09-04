<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Ellenőrzés</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $dokumentum->original_filename }}
                @if ($hatravan > 1)
                    · még {{ $hatravan - 1 }} irat vár utána
                @endif
            </p>
        </div>
        <a href="{{ route('beerkezo') }}" wire:navigate class="btn btn-ghost btn-sm">Vissza a Beérkezőbe</a>
    </div>

    @if ($dokumentum->tobb_irat_gyanu)
        <div class="alert alert-figyelem mb-4">
            Úgy tűnik, ebben a fájlban <strong>több különálló bizonylat</strong> van. Az alábbi adatok
            az elsőre vonatkoznak — a többit külön érdemes feltölteni.
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">

        {{-- Bal oldal: az eredeti --}}
        <div class="card overflow-hidden lg:sticky lg:top-20 lg:self-start">
            @if ($dokumentum->vanFajlja())
                @if ($dokumentum->kepE())
                    <img src="{{ route('dokumentum.fajl', $dokumentum) }}"
                         alt="{{ $dokumentum->original_filename }}"
                         class="max-h-[75vh] w-full bg-slate-100 object-contain">
                @else
                    <iframe src="{{ route('dokumentum.fajl', $dokumentum) }}#view=FitH"
                            title="{{ $dokumentum->original_filename }}"
                            class="h-[75vh] w-full bg-slate-100"></iframe>
                @endif
            @else
                <div class="empty m-4">
                    Ehhez az irathoz már nincs fájl — az export után törlődött.
                </div>
            @endif
        </div>

        {{-- Jobb oldal: a kiolvasott mezők --}}
        <form wire:submit="jovahagyas" class="card card-pad space-y-4">

            {{-- A kiemelés a bajt jelöli, nem a rendben lévőt: a jelöletlen mező
                 nem garancia, csak annyi, hogy nem akadt fenn semmin.

                 A jelzések a képernyőn lévő értékekre vonatkoznak, nem arra,
                 amit a modell írt: a mezők `.blur`-re szinkronizálnak, és az
                 ellenőrzések minden körben újrafutnak. Ezért javítás után
                 elmúlik a piros — és megjelenik, ha az ember ront el valamit. --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm border-l-4 border-red-500 bg-red-50"></span>
                    ellenőrzés bukott
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm border-l-4 border-amber-400 bg-amber-50"></span>
                    a modell bizonytalan
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm border border-dashed border-slate-400"></span>
                    nem nyilatkozott róla
                </span>
            </div>

            @php
                $keret = fn (string $mezo) => match ($this->sav($mezo)) {
                    'gyanus' => 'mezo-gyanus',
                    'bizonytalan' => 'mezo-bizonytalan',
                    'nincs_adat' => 'mezo-nincs-adat',
                    default => 'mezo-biztos',
                };
            @endphp

            <div>
                <label class="flabel" for="doc_type">{{ $cimkek['doc_type'] }}</label>
                <select id="doc_type" wire:model.blur="mezok.doc_type" class="control {{ $keret('doc_type') }}">
                    <option value="">— válassz —</option>
                    @foreach ($tipusok as $ertek => $cimke)
                        <option value="{{ $ertek }}">{{ $cimke }}</option>
                    @endforeach
                </select>
                <x-mezo-jelzes :hiba="$validatorHibak['doc_type'] ?? null" mezo="doc_type"/>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['supplier_name', 'supplier_tax_number', 'customer_name', 'customer_tax_number'] as $mezo)
                    <div>
                        <label class="flabel" for="{{ $mezo }}">{{ $cimkek[$mezo] }}</label>
                        <input id="{{ $mezo }}" type="text" wire:model.blur="mezok.{{ $mezo }}"
                               class="control {{ $keret($mezo) }}">
                        <x-mezo-jelzes :hiba="$validatorHibak[$mezo] ?? null" :mezo="$mezo"/>
                    </div>
                @endforeach
            </div>

            <div>
                <label class="flabel" for="doc_number">{{ $cimkek['doc_number'] }}</label>
                <input id="doc_number" type="text" wire:model.blur="mezok.doc_number" class="control {{ $keret('doc_number') }}">
                <x-mezo-jelzes :hiba="$validatorHibak['doc_number'] ?? null" mezo="doc_number"/>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                @foreach (['issue_date', 'fulfillment_date', 'due_date'] as $mezo)
                    <div>
                        <label class="flabel" for="{{ $mezo }}">{{ $cimkek[$mezo] }}</label>
                        <input id="{{ $mezo }}" type="date" wire:model.blur="mezok.{{ $mezo }}"
                               class="control {{ $keret($mezo) }}">
                        <x-mezo-jelzes :hiba="$validatorHibak[$mezo] ?? null" :mezo="$mezo"/>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-4 sm:grid-cols-5">
                @foreach (['net_amount', 'vat_amount', 'gross_amount', 'fizetendo'] as $mezo)
                    <div>
                        <label class="flabel" for="{{ $mezo }}">{{ $cimkek[$mezo] }}</label>
                        <input id="{{ $mezo }}" type="text" inputmode="decimal"
                               wire:model.blur="mezok.{{ $mezo }}" class="control text-right {{ $keret($mezo) }}">
                        <x-mezo-jelzes :hiba="$validatorHibak[$mezo] ?? null" :mezo="$mezo"/>
                    </div>
                @endforeach
                <div>
                    <label class="flabel" for="currency">{{ $cimkek['currency'] }}</label>
                    <input id="currency" type="text" maxlength="3" wire:model.blur="mezok.currency"
                           class="control uppercase {{ $keret('currency') }}">
                    <x-mezo-jelzes :hiba="$validatorHibak['currency'] ?? null" mezo="currency"/>
                </div>
            </div>

            {{-- Az ÁFA-bontás. Szerkeszthető, mert a sorok összege a legerősebb
                 ellenőrzésünk — és amit az ember nem tud javítani, azzal a
                 jelzés is csak bosszantás lenne. --}}
            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <span class="flabel">{{ $cimkek['afa_bontas'] }}</span>
                    <span class="text-xs text-slate-400">A bruttó számolt érték</span>
                </div>

                @if ($bontas !== [])
                    <table class="mt-1 w-full text-sm tabular-nums">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-slate-400">
                                <th class="py-1 text-left font-medium">Kulcs</th>
                                <th class="py-1 text-left font-medium">Kategória</th>
                                <th class="py-1 text-right font-medium">Nettó</th>
                                <th class="py-1 text-right font-medium">ÁFA</th>
                                <th class="py-1 text-right font-medium">Bruttó</th>
                                <th class="w-8"><span class="sr-only">Törlés</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bontas as $i => $sor)
                                <tr wire:key="bontas-{{ $i }}" class="align-top">
                                    <td class="py-1 pr-1">
                                        <input type="text" inputmode="decimal" aria-label="ÁFA-kulcs"
                                               wire:model.blur="bontas.{{ $i }}.kulcs"
                                               class="control px-2 py-1 text-right">
                                        @error('bontas.'.$i.'.kulcs')<p class="fhiba">{{ $message }}</p>@enderror
                                    </td>
                                    <td class="py-1 pr-1">
                                        <select aria-label="ÁFA-kategória" wire:model.blur="bontas.{{ $i }}.kategoria"
                                                class="control px-2 py-1">
                                            <option value="">—</option>
                                            @foreach ($kategoriak as $ertek => $cimke)
                                                <option value="{{ $ertek }}">{{ $cimke }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="py-1 pr-1">
                                        <input type="text" inputmode="decimal" aria-label="Adóalap"
                                               wire:model.blur="bontas.{{ $i }}.netto"
                                               class="control px-2 py-1 text-right">
                                        @error('bontas.'.$i.'.netto')<p class="fhiba">{{ $message }}</p>@enderror
                                    </td>
                                    <td class="py-1 pr-1">
                                        <input type="text" inputmode="decimal" aria-label="ÁFA összege"
                                               wire:model.blur="bontas.{{ $i }}.afa"
                                               class="control px-2 py-1 text-right">
                                        @error('bontas.'.$i.'.afa')<p class="fhiba">{{ $message }}</p>@enderror
                                    </td>
                                    <td class="py-2 text-right text-slate-500">
                                        {{ \App\Support\Osszeg::formaz(\App\Support\AfaBontas::brutto($sor['netto'] ?? null, $sor['afa'] ?? null)) }}
                                    </td>
                                    <td class="py-2 pl-1 text-right">
                                        <button type="button" wire:click="sorTorol({{ $i }})"
                                                class="btn btn-ghost btn-sm px-1.5 text-slate-400 hover:text-red-700"
                                                aria-label="Sor törlése">&times;</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <x-mezo-jelzes :hiba="$validatorHibak['afa_bontas'] ?? null" mezo="afa_bontas"/>

                @if (count($bontas) < $maxSor)
                    <button type="button" wire:click="sorHozzaad" class="btn btn-ghost btn-sm mt-1 px-0 text-blue-700">
                        + Sor hozzáadása
                    </button>
                @endif
            </div>

            <div>
                <label class="flabel" for="payment_method">{{ $cimkek['payment_method'] }}</label>
                <input id="payment_method" type="text" wire:model.blur="mezok.payment_method"
                       class="control {{ $keret('payment_method') }}">
            </div>

            <div>
                <label class="flabel" for="megjegyzes">Megjegyzés</label>
                <textarea id="megjegyzes" rows="2" wire:model="megjegyzes" class="control"></textarea>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                <p class="text-xs text-slate-400">
                    Jóváhagyás után a tétel az exportra vár.
                </p>
                <button type="submit" class="btn btn-primary">
                    Jóváhagyás
                    @if ($hatravan > 1)
                        <span class="text-blue-200">és következő</span>
                    @endif
                </button>
            </div>
        </form>
    </div>
</div>
