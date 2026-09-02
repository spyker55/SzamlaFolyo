<!DOCTYPE html>
<html lang="hu" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SzámlaFolyó' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
@php($ceg = app(\App\Support\Berlo::class)->ceg())
<div class="min-h-full lg:flex">

    {{-- Oldalsáv nagy képernyőn --}}
    <aside class="hidden w-60 shrink-0 border-r border-slate-200 bg-white lg:block">
        <div class="flex h-16 items-center gap-2 px-5">
            <x-logo class="h-7 w-7"/>
            <span class="text-base font-semibold text-slate-900">SzámlaFolyó</span>
        </div>
        <nav class="px-2 pb-6">
            <x-nav-links/>
        </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4
                       border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:px-8">
            <div class="flex items-center gap-2 lg:hidden">
                <x-logo class="h-6 w-6"/>
                <span class="font-semibold text-slate-900">SzámlaFolyó</span>
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

        {{-- Vízszintes menü kis képernyőn --}}
        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-2 py-2 lg:hidden">
            <x-nav-links :compact="true"/>
        </nav>

        <main class="flex-1 px-4 py-6 lg:px-8 lg:py-8">
            @if (session('siker'))
                <div class="alert alert-siker mb-4">{{ session('siker') }}</div>
            @endif
            @if (session('hiba'))
                <div class="alert alert-hiba mb-4">{{ session('hiba') }}</div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>

</body>
</html>
