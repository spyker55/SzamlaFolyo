<!DOCTYPE html>
<html lang="hu" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SzámlaFolyó' }}</title>
    <x-favicon/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
@php
    $ceg = app(\App\Support\Berlo::class)->ceg();
    $varakozo = $ceg ? \App\Models\Document::emberreVar()->count() : 0;
@endphp

{{--
    A menü két alakja. Nagy képernyőn állandó oldalsáv; mobilon fiók, amit a
    hamburger nyit.

    Mobilon korábban egy vízszintes csúszka ült a fejléc alatt. Az elvett egy
    sávot a képernyőből minden oldalon, a szélső pontjai pedig csak oldalra
    húzva látszottak — vagyis a „Beállítások" gyakorlatilag rejtve volt azon,
    aki nem próbálkozott. A fiók nem visz helyet, és egyszerre mutatja mind
    az ötöt.

    Az Alpine a Livewire csomagjából jön, külön script nélkül.
--}}
<div class="min-h-full lg:flex"
     x-data="{ menu: false }"
     x-on:keydown.escape.window="menu = false"
     x-effect="document.body.classList.toggle('overflow-hidden', menu)">

    {{-- Oldalsáv nagy képernyőn --}}
    <aside class="hidden w-60 shrink-0 border-r border-slate-200 bg-white lg:block">
        <div class="flex h-16 items-center px-5">
            <x-logo-sor jel="h-7 w-7" szoveg="text-xl"/>
        </div>
        <nav class="px-2 pb-6">
            <x-nav-links :varakozo="$varakozo"/>
        </nav>
    </aside>

    {{-- Fiók kis képernyőn --}}
    <div class="lg:hidden">
        <div x-show="menu" x-cloak x-transition.opacity
             x-on:click="menu = false"
             class="fixed inset-0 z-30 bg-slate-900/40"></div>

        <nav id="mobil-menu" aria-label="Főmenü"
             x-show="menu" x-cloak
             x-transition:enter="transition duration-200 ease-out"
             x-transition:enter-start="-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition duration-150 ease-in"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="-translate-x-full"
             class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white">
            <div class="flex h-16 shrink-0 items-center justify-between pr-2 pl-5">
                <x-logo-sor jel="h-7 w-7" szoveg="text-xl"/>
                <button type="button" x-on:click="menu = false" aria-label="Menü bezárása"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15"/>
                    </svg>
                </button>
            </div>

            {{-- A menüpontra koppintás egyben becsukja a fiókot: a wire:navigate
                 csak a válasz megérkezésekor cserélne oldalt, addig a nyitott
                 fiók úgy nézne ki, mintha nem történt volna semmi. --}}
            <div class="flex-1 overflow-y-auto px-2 pb-4" x-on:click="menu = false">
                <x-nav-links :varakozo="$varakozo"/>
            </div>

            <div class="shrink-0 border-t border-slate-200 px-5 py-3">
                <div class="truncate text-sm font-medium text-slate-900">{{ $ceg?->nevRovid() }}</div>
                <div class="truncate text-xs text-slate-500">{{ auth()->user()?->email }}</div>
            </div>
        </nav>
    </div>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4
                       border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:px-8">
            <div class="flex items-center gap-2 lg:hidden">
                {{-- A jelzőszám nem fér ki a gombra, a jelenléte viszont igen:
                     a pötty annyit mond, hogy van dolga valakinek. A számot a
                     kinyitott menü mutatja — és a képernyőolvasónak a felirat. --}}
                <button type="button" x-on:click="menu = true"
                        x-bind:aria-expanded="menu ? 'true' : 'false'"
                        aria-controls="mobil-menu"
                        aria-label="{{ $varakozo ? "Menü megnyitása – {$varakozo} irat vár" : 'Menü megnyitása' }}"
                        class="relative -ml-2 rounded-lg p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16"/>
                    </svg>
                    @if ($varakozo)
                        <span class="absolute top-1 right-1 h-2 w-2 rounded-full bg-amber-500 ring-2 ring-white"></span>
                    @endif
                </button>
                <x-logo-sor jel="h-7 w-7" szoveg="text-xl"/>
            </div>

            <div class="hidden text-sm text-slate-500 lg:block">
                {{ \App\Support\Ido::datum(now()) }}
            </div>

            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <div class="text-sm font-medium text-slate-900">{{ $ceg?->nevRovid() }}</div>
                    <div class="text-xs text-slate-500">{{ auth()->user()?->email }}</div>
                </div>
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-800 text-xs font-semibold text-white">
                    {{ auth()->user()?->monogram() }}
                </div>
                <form method="POST" action="{{ route('kijelentkezes') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Kilépés</button>
                </form>
            </div>
        </header>

        <main class="flex-1 px-4 py-6 lg:px-8 lg:py-8">
            @if (session('siker'))
                <div class="alert alert-siker mb-4">{{ session('siker') }}</div>
            @endif
            @if (session('hiba'))
                <div class="alert alert-hiba mb-4">{{ session('hiba') }}</div>
            @endif

            {{ $slot }}
        </main>

        {{-- A jogi oldalak belépve is elérhetők maradnak. --}}
        <footer class="border-t border-slate-200 px-4 py-5 lg:px-8">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
                <x-jogi-linkek link-osztaly="hover:text-slate-900"/>
                <span class="ml-auto">© {{ date('Y') }} SzámlaFolyó</span>
            </div>
        </footer>
    </div>
</div>

</body>
</html>
