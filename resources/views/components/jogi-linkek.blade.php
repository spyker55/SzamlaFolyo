@props(['linkOsztaly' => ''])
{{--
    A három jogi oldal linkje. Egy helyen, mert két láblécben szerepel (a
    nyilvános oldalén és az alkalmazásén), és két listát nem lehet kézzel
    szinkronban tartani — a negyedik dokumentum épp az egyiket kihagyná.

    A linkek stílusa hívónként más, mert a két lábléc sem egyforma; a **lista**
    viszont ugyanaz.
--}}
@foreach ([
    ['aszf', 'ÁSZF'],
    ['adatkezeles', 'Adatkezelés'],
    ['impresszum', 'Impresszum'],
] as [$utvonal, $cimke])
    <a href="{{ route($utvonal) }}" class="{{ $linkOsztaly }}">{{ $cimke }}</a>
@endforeach
