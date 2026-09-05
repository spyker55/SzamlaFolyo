@props(['cim'])
{{--
    A jogi oldalak elrendezése.

    Szándékosan nem az alkalmazás kerete: az ÁSZF-et a belépés **előtt** kell
    tudni elolvasni, különben ahhoz kellene fiók, amihez a fiók feltétele
    kötődik. Ugyanez az oldal jön a belépett felhasználónak is — a tartalom
    ugyanaz, felesleges két változatot tartani belőle.
--}}
<!DOCTYPE html>
<html lang="hu" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $cim }} — SzámlaFolyó</title>
    <x-favicon/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col">

<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-3xl items-center px-4 py-4 sm:px-6">
        <a href="{{ route('kezdolap') }}" class="logo-link">
            <x-logo-sor jel="h-7 w-7" szoveg="text-xl"/>
        </a>
    </div>
</header>

<main class="mx-auto w-full max-w-3xl flex-1 px-4 py-10 sm:px-6">
    <h1 class="mb-6 text-2xl font-semibold text-slate-900">{{ $cim }}</h1>

    <div class="space-y-4 text-sm leading-relaxed text-slate-700">
        {{ $slot }}
    </div>

    {{-- Kiút a fejlécbeli logón kívül is. Ide sokan a regisztrációs űrlap
         linkjéről érkeznek új lapon, de nem mind — akit a kereső hozott ide,
         annak a lap alján kell találnia egy utat tovább. --}}
    <p class="mt-10 text-sm">
        <a href="{{ route('kezdolap') }}" class="font-medium text-blue-700 hover:underline">
            ← Vissza a főoldalra
        </a>
    </p>
</main>

<footer class="border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-5 text-xs text-slate-500 sm:px-6">
        <x-jogi-linkek link-osztaly="hover:text-slate-900"/>
        <span class="ml-auto">© {{ date('Y') }} SzámlaFolyó</span>
    </div>
</footer>

</body>
</html>
