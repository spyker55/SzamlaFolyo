<div @if ($dolgozikMeg) wire:poll.3s="lepteti" @endif>

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Beérkező</h1>
            <p class="mt-1 text-sm text-slate-500">
                Húzd ide a bizonylatokat, vagy küldd őket e-mailben.
            </p>
        </div>
        <div class="text-right text-sm">
            <div class="text-slate-500">Beküldési cím</div>
            <code class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">{{ $ceg->beerkezteoCim() }}</code>
        </div>
    </div>

    @if ($akadaly)
        <div class="alert alert-figyelem mb-4">
            {{ $akadaly }}
            <a href="{{ route('beallitasok') }}" wire:navigate class="font-medium underline">Csomagok</a>
        </div>
    @elseif ($maradek < PHP_INT_MAX)
        <p class="mb-4 text-xs text-slate-500">
            A keretedből még <strong>{{ $maradek }}</strong> dokumentum van hátra.
        </p>
    @endif

    {{-- Feltöltés --}}
    <label for="fajlok"
           class="mb-6 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2
                  border-dashed border-slate-300 bg-white/70 px-6 py-10 text-center transition
                  hover:border-blue-400 hover:bg-blue-50/40">
        <input id="fajlok" type="file" wire:model="fajlok" multiple class="sr-only"
               accept="application/pdf,image/jpeg,image/png,image/webp">
        <span class="text-sm font-medium text-slate-700">Bizonylatok feltöltése</span>
        <span class="mt-1 text-xs text-slate-500">PDF, JPG, PNG vagy WEBP — legfeljebb 20 MB darabonként</span>
        <span wire:loading wire:target="fajlok,feltoltes" class="mt-2 text-xs text-blue-700">Feltöltés folyamatban…</span>
    </label>

    @if ($feltoltesiHibak)
        <div class="alert alert-hiba mb-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach ($feltoltesiHibak as $hiba)
                    <li>{{ $hiba }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($dokumentumok->isEmpty())
        <div class="empty">
            Itt jelennek meg a feltöltött és az e-mailben beküldött bizonylatok.
        </div>
    @else
        <div class="card overflow-hidden">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="th">Bizonylat</th>
                        <th class="th">Típus</th>
                        <th class="th">Partner</th>
                        <th class="th">Összeg</th>
                        <th class="th">Állapot</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($dokumentumok as $d)
                    <tr class="trow" wire:key="dok-{{ $d->id }}">
                        <td class="td">
                            <div class="font-medium text-slate-900">{{ $d->megnevezes() }}</div>
                            <div class="text-xs text-slate-400">
                                {{ $d->source === 'email' ? 'E-mailben érkezett' : 'Feltöltve' }} ·
                                {{ \App\Support\Ido::datumIdo($d->created_at) }}
                            </div>
                        </td>
                        <td class="td">{{ \App\Enums\DokumentumTipus::cimkeje($d->doc_type?->value) }}</td>
                        <td class="td">{{ $d->supplier_name ?? '—' }}</td>
                        <td class="td whitespace-nowrap">{{ \App\Support\Osszeg::formaz($d->gross_amount, $d->currency) }}</td>
                        <td class="td">
                            @php
                                $stilus = match ($d->status) {
                                    \App\Enums\DokumentumAllapot::EllenorzesreVar => 'badge-varakozo',
                                    \App\Enums\DokumentumAllapot::Hiba => 'badge-hiba',
                                    \App\Enums\DokumentumAllapot::Duplikatum => 'badge-semleges',
                                    default => 'badge-semleges',
                                };
                            @endphp
                            <span class="badge {{ $stilus }}">{{ $d->status->cimke() }}</span>
                            @if ($d->error)
                                <div class="mt-1 max-w-xs text-xs text-red-700">{{ $d->error }}</div>
                            @endif
                            @if ($d->status === \App\Enums\DokumentumAllapot::Duplikatum)
                                <div class="mt-1 text-xs text-slate-400">Ez a fájl már bent van.</div>
                            @endif
                        </td>
                        <td class="td text-right whitespace-nowrap">
                            @if ($d->status === \App\Enums\DokumentumAllapot::EllenorzesreVar)
                                <a href="{{ route('ellenorzes', $d) }}" wire:navigate class="btn btn-primary btn-sm">Ellenőrzés</a>
                            @elseif ($d->status === \App\Enums\DokumentumAllapot::Hiba)
                                <button wire:click="ujra({{ $d->id }})" class="btn btn-secondary btn-sm">Újra</button>
                            @endif
                            <button wire:click="torol({{ $d->id }})"
                                    wire:confirm="Biztosan törlöd? A fájl is törlődik."
                                    class="btn btn-ghost btn-sm">Törlés</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
