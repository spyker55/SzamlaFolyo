<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Tételek</h1>
            <p class="mt-1 text-sm text-slate-500">Jóváhagyva, exportra várva.</p>
        </div>
        @if ($tetelek->total() > 0)
            <a href="{{ route('export') }}" wire:navigate class="btn btn-primary">Exportálás</a>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="kereses" class="control w-full sm:w-72"
               placeholder="Keresés partnerre vagy bizonylatszámra">
        <select wire:model.live="tipus" class="control w-full sm:w-56">
            <option value="">Minden típus</option>
            @foreach ($tipusok as $ertek => $cimke)
                <option value="{{ $ertek }}">{{ $cimke }}</option>
            @endforeach
        </select>
    </div>

    @if ($osszesites)
        <div class="mb-4 flex flex-wrap gap-3">
            @foreach ($osszesites as $penznem => $ossz)
                <div class="card card-pad">
                    <div class="text-xs text-slate-500">{{ $ossz['darab'] }} könyvelendő tétel · {{ $penznem }}</div>
                    <div class="mt-1 text-sm text-slate-700">
                        Nettó <strong>{{ \App\Support\Osszeg::formaz($ossz['netto']) }}</strong> ·
                        ÁFA <strong>{{ \App\Support\Osszeg::formaz($ossz['afa']) }}</strong> ·
                        Bruttó <strong>{{ \App\Support\Osszeg::formaz($ossz['brutto']) }}</strong>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($tetelek->isEmpty())
        <div class="empty">
            Nincs exportra váró tétel. Az ellenőrzött és jóváhagyott bizonylatok itt gyűlnek.
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="th">Típus</th>
                        <th class="th">Szállító</th>
                        <th class="th">Bizonylatszám</th>
                        <th class="th">Kelt</th>
                        <th class="th text-right">Nettó</th>
                        <th class="th text-right">ÁFA</th>
                        <th class="th text-right">Bruttó</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($tetelek as $t)
                    <tr class="trow" wire:key="tetel-{{ $t->id }}">
                        <td class="td">{{ \App\Enums\DokumentumTipus::cimkeje($t->doc_type?->value) }}</td>
                        <td class="td">
                            <div>{{ $t->supplier_name ?? '—' }}</div>
                            <div class="text-xs text-slate-400">{{ $t->supplier_tax_number }}</div>
                        </td>
                        <td class="td">{{ $t->doc_number ?? '—' }}</td>
                        <td class="td whitespace-nowrap">{{ $t->issue_date?->format('Y. m. d.') ?? '—' }}</td>
                        <td class="td text-right whitespace-nowrap">{{ \App\Support\Osszeg::formaz($t->net_amount) }}</td>
                        <td class="td text-right whitespace-nowrap">{{ \App\Support\Osszeg::formaz($t->vat_amount) }}</td>
                        <td class="td text-right font-medium whitespace-nowrap">
                            {{ \App\Support\Osszeg::formaz($t->gross_amount, $t->currency) }}
                        </td>
                        <td class="td text-right">
                            <button wire:click="javitasra({{ $t->id }})" class="btn btn-ghost btn-sm">Javítás</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $tetelek->links() }}</div>
    @endif
</div>
