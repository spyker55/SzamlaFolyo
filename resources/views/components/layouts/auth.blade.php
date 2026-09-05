<!DOCTYPE html>
<html lang="hu" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SzámlaFolyó' }}</title>
    <x-favicon/>
    <x-betukeszlet/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center px-4 py-12">
<div class="w-full max-w-md">
    {{-- A fejléc egyben kiút: sokan megszokásból a névre kattintanak, ha
         vissza akarnak jutni. Itt is működik — de nem ez az egyetlen út,
         mert egy kattintható logó nem mondja meg magáról, hogy az. A kiírt
         link a kártya alatt van. --}}
    <a href="{{ route('kezdolap') }}" class="logo-link mb-6 flex justify-center">
        <x-logo-sor jel="h-9 w-9" szoveg="text-2xl"/>
    </a>

    <div class="card card-pad">
        @if (session('siker'))
            <div class="alert alert-siker mb-4">{{ session('siker') }}</div>
        @endif
        {{ $slot }}
    </div>

    {{-- Vissza a nyilvános oldalra. Aki idáig eljutott, még nem biztos, hogy
         be akar lépni: lehet, hogy csak az árakat keresi.

         `wire:navigate` nélkül, szándékosan: a nyitólap nem Livewire-oldal, és
         onnan is teljes betöltéssel jön ide a látogató — a két irány maradjon
         egyforma. --}}
    <p class="mt-6 text-center text-sm">
        <a href="{{ route('kezdolap') }}" class="font-medium text-blue-700 hover:underline">
            ← Vissza a főoldalra
        </a>
    </p>

    <p class="mt-4 text-center text-xs text-slate-400">
        A bizonylatokat AI olvassa ki, az adatok magyar szervereken tárolódnak.
    </p>
</div>

<x-fejlesztes-alatt/>
</body>
</html>
