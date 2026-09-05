@props(['class' => 'h-7 w-7'])
{{--
    A márkajel: tömör terrakotta négyzet, benne krém dokumentum levágott
    lapsarokkal, a szövegsorok helyén két hullám — a lapon átfolyó adat.

    A geometria a designcsomagból való (`design/logo/`), pixelre ugyanaz: 56-os
    viewBox, ezek a path-ok, ezek a hexák. Ezért nem `currentColor`: a jelet nem
    szabad átszínezni, elforgatni, lekerekíteni vagy árnyékolni — a saját
    hátterét hozza magával, tehát színes felületen is megáll magában.

    32 pixel alatt a `public/favicon.svg` egyszerűsített változata való ide (egy
    hullám, vastagabb vonal); a felületen ekkora jel nem fordul elő.
--}}
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 56 56"
     xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <rect width="56" height="56" fill="#be6846"/>
    <path d="M14 11 H34 L42 19 V45 H14 Z" fill="#f5ece2"/>
    <path d="M18 27 C22 23, 26 31, 30 27 S38 23, 38 27" stroke="#be6846" stroke-width="3" fill="none"/>
    <path d="M18 35 C22 31, 26 39, 30 35 S38 31, 38 35" stroke="#be6846" stroke-width="3" fill="none"/>
</svg>
