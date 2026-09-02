<div class="max-w-3xl">
    <h1 class="text-xl font-semibold text-slate-900">Export</h1>
    <p class="mt-1 mb-6 text-sm text-slate-500">
        A jóváhagyott, még nem exportált tételekből készül. Ami kimegy, az az Archívumba kerül.
    </p>

    <div class="card card-pad space-y-5">

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="flabel" for="tol">Beérkezés — tól</label>
                <input id="tol" type="date" wire:model.live="tolDatum" class="control">
            </div>
            <div>
                <label class="flabel" for="ig">Beérkezés — ig</label>
                <input id="ig" type="date" wire:model.live="igDatum" class="control">
            </div>
            <div>
                <label class="flabel" for="tipus">Típus</label>
                <select id="tipus" wire:model.live="tipus" class="control">
                    <option value="">Mind</option>
                    @foreach ($tipusok as $ertek => $cimke)
                        <option value="{{ $ertek }}">{{ $cimke }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="rounded-lg bg-slate-50 px-4 py-3">
            <div class="text-sm font-medium text-slate-800">{{ $darab }} tétel kerül exportba</div>
            @foreach ($osszesites as $penznem => $ossz)
                <div class="mt-1 text-xs text-slate-600">
                    {{ $penznem }} — nettó {{ \App\Support\Osszeg::formaz($ossz['netto']) }} ·
                    ÁFA {{ \App\Support\Osszeg::formaz($ossz['afa']) }} ·
                    bruttó {{ \App\Support\Osszeg::formaz($ossz['brutto']) }}
                    <span class="text-slate-400">({{ $ossz['darab'] }} könyvelendő)</span>
                </div>
            @endforeach
        </div>

        <div>
            <span class="flabel">Formátum</span>
            <div class="flex flex-wrap gap-2">
                @foreach (['xlsx' => 'Excel (xlsx)', 'csv' => 'CSV', 'json' => 'JSON'] as $ertek => $cimke)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm
                                  {{ $formatum === $ertek ? 'border-blue-600 bg-blue-50 text-blue-900' : 'border-slate-300 bg-white' }}">
                        <input type="radio" wire:model.live="formatum" value="{{ $ertek }}" class="sr-only">
                        {{ $cimke }}
                    </label>
                @endforeach
            </div>
        </div>

        @if ($ceg->file_retention_days === 0)
            <div class="alert alert-figyelem">
                <strong>Az export után az eredeti PDF-ek és képek törlődnek a szerverről.</strong>
                Az adatok az Archívumban maradnak, de a bizonylat képe nem hívható vissza.
                Töltsd le őket most, ha meg akarod őrizni — a megőrzési kötelezettség a tiéd.
            </div>
        @else
            <div class="alert alert-info">
                Az eredeti fájlok az export után még {{ $ceg->file_retention_days }} napig elérhetők maradnak.
            </div>
        @endif

        @error('export') <div class="alert alert-hiba">{{ $message }}</div> @enderror

        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
            <button type="button" wire:click="eredetikZip" class="btn btn-secondary" @disabled($darab === 0)>
                Eredeti bizonylatok letöltése (ZIP)
            </button>

            <button type="button" wire:click="exportal"
                    wire:confirm="Elkészítjük az exportot. Az eredeti fájlok ezután törlődnek. Folytatod?"
                    class="btn btn-primary" @disabled($darab === 0)>
                <span wire:loading.remove wire:target="exportal">Export elkészítése</span>
                <span wire:loading wire:target="exportal">Készül…</span>
            </button>

            @if ($eredetikLetoltve)
                <span class="text-xs text-emerald-700">Az eredetik letöltve.</span>
            @endif
        </div>
    </div>
</div>
