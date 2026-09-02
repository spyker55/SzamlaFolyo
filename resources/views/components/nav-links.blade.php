@props(['compact' => false])

@php
    $ceg = app(\App\Support\Berlo::class)->ceg();
    $varakozo = $ceg
        ? \App\Models\Document::query()
            ->whereIn('status', [
                \App\Enums\DokumentumAllapot::EllenorzesreVar->value,
                \App\Enums\DokumentumAllapot::Hiba->value,
            ])->count()
        : 0;

    $elemek = [
        ['beerkezo',   'Beérkező',    $varakozo],
        ['tetelek',    'Tételek',     null],
        ['export',     'Export',      null],
        ['archivum',   'Archívum',    null],
        ['beallitasok','Beállítások', null],
    ];
@endphp

@foreach ($elemek as [$utvonal, $cimke, $jelzo])
    <a href="{{ route($utvonal) }}" wire:navigate
       @class([
           'nav-item' => ! $compact,
           'nav-item-aktiv' => ! $compact && request()->routeIs($utvonal),
           'shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap' => $compact,
           'bg-blue-50 text-blue-800' => $compact && request()->routeIs($utvonal),
           'text-slate-600' => $compact && ! request()->routeIs($utvonal),
       ])>
        <span>{{ $cimke }}</span>
        @if ($jelzo)
            <span class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold text-white">
                {{ $jelzo }}
            </span>
        @endif
    </a>
@endforeach
