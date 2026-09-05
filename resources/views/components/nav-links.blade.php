{{--
    A menüpontok. Egyetlen alak van belőlük: ugyanaz a lista ül a nagy képernyő
    oldalsávjában és a mobil fiókban. Korábban volt egy „compact" változat is a
    felső csúszkához — a csúszkával együtt az is eltűnt.

    A jelzőszámot kívülről kapja. Az elrendezés két helyen mutatja (a menüpont
    mellett és a hamburgeren), és nem akarunk kétszer számolni ugyanazt.
--}}
@props(['varakozo' => 0])

@php
    $elemek = [
        ['beerkezo', 'Beérkező', $varakozo],
        ['tetelek', 'Tételek', null],
        ['export', 'Export', null],
        ['archivum', 'Archívum', null],
        ['beallitasok', 'Beállítások', null],
    ];
@endphp

@foreach ($elemek as [$utvonal, $cimke, $jelzo])
    <a href="{{ route($utvonal) }}" wire:navigate
       @class([
           'nav-item',
           'nav-item-aktiv' => request()->routeIs($utvonal),
       ])>
        <span>{{ $cimke }}</span>
        @if ($jelzo)
            <span class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold text-white">
                {{ $jelzo }}
            </span>
        @endif
    </a>
@endforeach
