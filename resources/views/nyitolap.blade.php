<!DOCTYPE html>
<html lang="hu" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SzámlaFolyó — dokumentumból ellenőrzött, könyvelésre kész adat</title>
    <meta name="description" content="Küldd tovább a számlát vagy nyugtát, a SzámlaFolyó kiolvassa. Te csak azt ellenőrzöd, amiben nem biztos. Export XLSX, CSV vagy JSON formátumban.">
    <x-betukeszlet/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full overflow-x-hidden bg-vaszon text-slate-800 antialiased">

@php
    $probaNapok = (int) config('szamlafolyo.trial.days');
    $probaDarab = (int) config('szamlafolyo.trial.documents');
    $csomagok = (array) config('szamlafolyo.plans');
@endphp

<nav class="fixed top-0 z-50 w-full border-b border-zsalya/20 bg-vaszon/90 backdrop-blur-md">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-20 items-center justify-between">
            <a href="#" class="flex flex-shrink-0 items-center gap-3">
                <span class="flex h-10 w-10 -skew-x-6 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 text-white shadow-lg shadow-blue-500/30">
                    <x-logo class="h-6 w-6 text-white" :mono="true"/>
                </span>
                <span class="text-2xl font-extrabold tracking-tight text-slate-800">Számla<span class="text-blue-700">Folyó</span></span>
            </a>

            <div class="hidden items-center space-x-8 md:flex">
                <a href="#folyamat" class="font-medium text-slate-600 transition-colors hover:text-blue-700">Hogyan működik?</a>
                <a href="#elonyok" class="font-medium text-slate-600 transition-colors hover:text-blue-700">Előnyök</a>
                <a href="#arak" class="font-medium text-slate-600 transition-colors hover:text-blue-700">Árak</a>
                <a href="{{ route('bejelentkezes') }}" class="font-medium text-slate-600 transition-colors hover:text-blue-700">Bejelentkezés</a>
                <a href="{{ route('regisztracio') }}" class="rounded-full bg-blue-500 px-5 py-2.5 font-medium text-white shadow-lg shadow-blue-500/20 transition-all hover:bg-blue-600">Ingyenes próba</a>
            </div>

            <a href="{{ route('bejelentkezes') }}" class="rounded-full bg-blue-500 px-4 py-2 text-sm font-medium text-white md:hidden">Belépés</a>
        </div>
    </div>
</nav>

{{-- Fejléc --}}
<section class="relative overflow-hidden pt-32 pb-20 lg:pt-44 lg:pb-28">
    <div aria-hidden="true" class="pointer-events-none absolute top-20 -left-20 -z-10 h-96 w-96 rounded-full bg-zsalya opacity-25 blur-[80px]"></div>
    <div aria-hidden="true" class="pointer-events-none absolute top-40 right-10 -z-10 h-96 w-96 rounded-full bg-mustar opacity-20 blur-[80px]"></div>

    <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid items-center gap-12 lg:grid-cols-[7fr_5fr] lg:gap-10">

            <div class="max-w-2xl">
                <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-blue-500/20 bg-blue-500/10 px-3 py-1 text-sm font-semibold text-blue-700">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-500 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </span>
                    A legrövidebb út a bizonylattól a könyvelésig
                </div>

                {{--
                    A sortörést a böngésző osztja el (`text-balance`), nem
                    kézzel elhelyezett `<br>`-ek. Fix töréssel a féloszlopban
                    minden köztes szélességen más helyen csúszott szét a cím —
                    és épp a kiemelt szókapcsolat maradt egy szó a saját során.
                --}}
                <h1 class="mb-6 text-4xl leading-tight font-extrabold text-balance text-slate-800 sm:text-5xl">
                    Dokumentumból
                    <span class="bg-gradient-to-r from-blue-500 to-blue-700 bg-clip-text text-transparent">ellenőrzött, könyvelésre kész adat</span>
                    — percek alatt.
                </h1>

                <p class="mb-8 text-lg leading-relaxed text-slate-600 sm:text-xl">
                    <strong class="font-bold text-slate-800">Küldd tovább a számlát vagy nyugtát. A SzámlaFolyó kiolvassa.</strong>
                    Te csak azt ellenőrzöd, amiben nem biztos. Export, és kész. Nem funkciókat halmozunk,
                    hanem a legkisebb, leggyorsabb munkafolyamatot adjuk.
                </p>

                <div class="flex flex-col gap-4 sm:flex-row">
                    <a href="{{ route('regisztracio') }}"
                       class="flex items-center justify-center gap-2 rounded-full bg-blue-500 px-8 py-4 text-center text-lg font-bold text-white shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-600">
                        Kipróbálom ingyen
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <a href="#folyamat"
                       class="rounded-full border border-zsalya/30 bg-white px-8 py-4 text-center text-lg font-semibold text-slate-800 transition-all hover:bg-slate-50">
                        Nézzük, hogyan működik
                    </a>
                </div>

                {{--
                    A próbaidő három ténye, közvetlenül a gomb alatt. Ez az a
                    három kérdés, amit a látogató a kattintás előtt feltesz —
                    és mindhárom a konfigurációból jön, nem kézzel beírt szám:
                    ha a keret változik, a marketing is vele változik.
                --}}
                <dl class="mt-10 flex flex-wrap gap-x-8 gap-y-4">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zsalya/20 text-zsalya">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <div>
                            <dt class="text-sm font-bold text-slate-800">{{ $probaNapok }} nap próbaidő</dt>
                            <dd class="text-xs text-slate-500">Kötelezettség nélkül</dd>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zsalya/20 text-zsalya">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <div>
                            <dt class="text-sm font-bold text-slate-800">{{ $probaDarab }} dokumentum ingyen</dt>
                            <dd class="text-xs text-slate-500">Teljes funkcionalitással</dd>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zsalya/20 text-zsalya">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <div>
                            <dt class="text-sm font-bold text-slate-800">Nem kér bankkártyát</dt>
                            <dd class="text-xs text-slate-500">A regisztrációhoz sem</dd>
                        </div>
                    </div>
                </dl>
            </div>

            {{-- Az ellenőrző képernyő, ahogy valóban kinéz: a kiemelés a bajt
                 jelöli, a rendben lévő mező sima keretet kap. --}}
            <div class="flex h-full w-full items-center justify-center lg:min-h-[500px]">
              <div class="relative w-full max-w-md">
                <div class="overflow-hidden rounded-2xl border border-zsalya/20 bg-white shadow-2xl">
                    <div class="flex items-center justify-between border-b border-zsalya/20 bg-vaszon/50 p-4">
                        <div class="text-sm font-bold text-slate-800">Dokumentum jóváhagyása</div>
                        <div class="flex gap-1.5">
                            <span class="h-3 w-3 rounded-full bg-blue-500"></span>
                            <span class="h-3 w-3 rounded-full bg-mustar"></span>
                            <span class="h-3 w-3 rounded-full bg-zsalya"></span>
                        </div>
                    </div>

                    <div class="space-y-5 p-6">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-bold tracking-wider text-slate-500 uppercase">Típus</span>
                            <span class="rounded-md bg-zsalya/20 px-2 py-1 text-xs font-bold text-slate-700">Belföldi számla</span>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-slate-500">Szállító neve</label>
                            <input type="text" disabled value="Kovács és Társa Kft."
                                   class="w-full rounded-md border border-slate-300 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-800">
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-slate-500">Adószám</label>
                            <input type="text" disabled value="88888888-2-42"
                                   class="w-full rounded-md border border-slate-300 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-800">
                        </div>

                        <div class="space-y-1">
                            <label class="flex justify-between text-xs font-semibold text-slate-500">
                                Végösszeg (bruttó)
                                <span class="rounded bg-bizonytalan/15 px-1.5 text-[10px] font-bold text-bizonytalan">ELLENŐRIZENDŐ</span>
                            </label>
                            <input type="text" disabled value="125 000 Ft"
                                   class="w-full rounded-md border border-l-4 border-bizonytalan bg-bizonytalan/5 px-4 py-2 text-sm font-bold text-slate-800">
                            <p class="text-[11px] text-bizonytalan">A nettó és az ÁFA összege nem adja ki a bruttót.</p>
                        </div>

                        <div class="w-full rounded-lg bg-tinta py-3 text-center text-sm font-bold text-vaszon">
                            Jóváhagyás
                        </div>
                    </div>
                </div>

                <div class="absolute top-full right-0 -mt-8 flex items-center gap-3 rounded-xl border border-zsalya/10 bg-white p-4 shadow-xl">
                    <span class="rounded-full bg-zsalya/20 p-2 text-zsalya">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    </span>
                    <div>
                        <p class="text-xs font-bold text-slate-800">Sikeres export</p>
                        <p class="text-[10px] text-slate-500">szamlak_09_24.xlsx</p>
                    </div>
                </div>
              </div>
            </div>

        </div>
    </div>
</section>

<section class="border-y border-zsalya/20 bg-vaszon py-10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <p class="mb-6 text-center text-sm font-bold tracking-widest text-slate-500 uppercase">Exportformátumok</p>
        <div class="flex flex-wrap items-center justify-center gap-8 text-slate-800 opacity-70 transition-all duration-500 hover:opacity-100 md:gap-16">
            <span class="font-mono text-xl font-bold">.XLSX</span>
            <span class="font-mono text-xl font-bold">.CSV</span>
            <span class="font-mono text-xl font-bold">{ JSON }</span>
        </div>
    </div>
</section>

{{-- Munkafolyamat --}}
<section id="folyamat" class="bg-slate-50 py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto mb-16 max-w-3xl text-center">
            <h2 class="mb-3 text-sm font-bold tracking-widest text-blue-700 uppercase">A munkafolyamat</h2>
            <p class="mb-4 text-3xl font-extrabold text-slate-800 md:text-4xl">Nem kell mindent túlbonyolítani.</p>
            <p class="text-lg text-slate-600">A SzámlaFolyó azért készült, hogy elvégezze helyetted az adatrögzítést. A kevesebb gomb néha több szabadidőt jelent.</p>
        </div>

        <div class="relative grid gap-8 md:grid-cols-4">
            <div aria-hidden="true" class="absolute top-12 right-[10%] left-[10%] hidden h-0.5 bg-zsalya/20 md:block"></div>

            @foreach ([
                ['Beküldés', 'Töltsd fel a fájlokat, vagy küldd tovább őket a cégedhez tartozó e-mail címre.', 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                ['Kiolvasás', 'Az e-számla XML-jét gép olvassa, modell nélkül. Papír vagy szkennelt PDF esetén jön az AI.', 'M13 10V3L4 14h7v7l9-11h-7z'],
                ['Ellenőrzés', 'Megjelöljük, ami bizonytalan vagy ellentmondásos. Amit nem jelöltünk meg, azzal nincs dolgod.', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                ['Export', 'Egy kattintás, és letöltöd XLSX, CSV vagy JSON formátumban a könyveléshez.', 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4'],
            ] as $i => [$cim, $szoveg, $ikon])
                <div class="relative z-10 text-center">
                    <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl border-4 border-white bg-vaszon text-blue-700 shadow-lg">
                        <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $ikon }}"/></svg>
                    </div>
                    <h3 class="mb-2 text-xl font-bold text-slate-800">{{ $i + 1 }}. {{ $cim }}</h3>
                    <p class="text-sm text-slate-600">{{ $szoveg }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Előnyök --}}
<section id="elonyok" class="bg-vaszon py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid items-center gap-16 lg:grid-cols-2">

            <div>
                <h2 class="mb-6 text-3xl font-extrabold text-slate-800">Nem hisszük el a gépnek, amit mond.</h2>
                <p class="mb-8 text-lg leading-relaxed text-slate-600">
                    Egy kiolvasó modell akkor is magabiztos, amikor téved. Ezért minden bizonylat átmegy olyan
                    ellenőrzéseken is, amelyeknek semmi közük a modellhez — és ami ezeken elhasal, azt megjelöljük.
                </p>

                <ul class="space-y-5">
                    @foreach ([
                        ['Az adószám matematikája', 'A magyar adószám ellenőrző számjegye vagy stimmel, vagy nem. Ez nem vélemény kérdése.'],
                        ['Nettó + ÁFA = bruttó', 'Az ÁFA-bontás soronként is számol: ha a sorok nem adják ki a végösszeget, azt jelezzük.'],
                        ['Kézírás külön elbírálás alá esik', 'A kézzel írt bizonylatnál a nevet nem lehet ellenőrizni semmivel — ezért azt mindig átnézésre jelöljük, akkor is, ha a modell magabiztos.'],
                        ['Vegyes bizonylatok', 'Belföldi számla, nyugta, külföldi bizonylat, fotózott blokk és e-számla XML — egy folyamatban.'],
                        ['Rendszerfüggetlenség', 'Nincs bezártság. Az adatot úgy kapod meg (XLSX, CSV, JSON), ahogy a saját rendszered kéri.'],
                    ] as [$cim, $szoveg])
                        <li class="flex items-start">
                            <span class="mt-1 mr-3 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-zsalya/20 text-zsalya">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <div>
                                <h3 class="font-bold text-slate-800">{{ $cim }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ $szoveg }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="relative overflow-hidden rounded-2xl border border-zsalya/20 bg-slate-50 p-8 shadow-inner">
                <div aria-hidden="true" class="absolute top-0 right-0 h-32 w-32 rounded-full bg-mustar/30 opacity-50 blur-3xl"></div>

                <div class="relative z-10 space-y-4">
                    {{--
                        Az osztálynevek szándékosan teljes alakban állnak a
                        tömbben, nem `bg-{$szin}` alakban összerakva: a Tailwind
                        a forrásfájlokat **szövegként** olvassa, az összefűzött
                        nevet nem találná meg, és a szín némán eltűnne.
                    --}}
                    @foreach ([
                        ['TELEKOM_szamla_09.pdf', 'Kiolvasva · nincs megjelölt mező', 'bg-zsalya/15 text-zsalya', 'bg-zsalya', false],
                        ['etterem_ebed_blokk.jpg', 'Ellenőrzést igényel · 1 mező bizonytalan', 'bg-bizonytalan/15 text-bizonytalan', 'bg-bizonytalan', true],
                        ['AWS_Invoice_Aug.pdf', 'Kiolvasva · USD, pénznem szerint összesítve', 'bg-zsalya/15 text-zsalya', 'bg-zsalya', false],
                    ] as [$fajl, $allapot, $ikonSzin, $pontSzin, $kiemelt])
                        <div @class([
                            'flex items-center justify-between rounded-xl border border-zsalya/10 bg-white p-4 shadow-sm',
                            'ring-2 ring-bizonytalan' => $kiemelt,
                        ])>
                            <div class="flex items-center gap-4">
                                <span class="flex h-10 w-10 items-center justify-center rounded-lg {{ $ikonSzin }}">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </span>
                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ $fajl }}</p>
                                    <p class="text-xs text-slate-500">{{ $allapot }}</p>
                                </div>
                            </div>
                            <span class="h-3 w-3 shrink-0 rounded-full {{ $pontSzin }} shadow-sm"></span>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</section>

{{-- Árak --}}
<section id="arak" class="bg-tinta py-24 text-vaszon">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto mb-16 max-w-3xl text-center">
            <h2 class="mb-3 text-sm font-bold tracking-widest text-mustar uppercase">Árazás</h2>
            <p class="mb-6 text-3xl font-extrabold md:text-4xl">Fizess az értékért. Nincsenek rejtett költségek.</p>
            <p class="text-vaszon/70">
                A próba {{ $probaNapok }} napig tart, {{ $probaDarab }} dokumentumig, és nem kér bankkártyát.
            </p>
        </div>

        <div class="mx-auto grid max-w-5xl items-center gap-8 md:grid-cols-3">

            @foreach ([
                ['kicsi',   'Kisebb vállalkozásoknak, kockázatmentes próbára.', false],
                ['kozepes', 'A legtöbb KKV-nak. Pörgesd fel az adminisztrációt.', true],
                ['nagy',    'Könyvelőirodáknak, nagy forgalmú cégeknek.', false],
            ] as [$kulcs, $leiras, $ajanlott])
                @php($cs = $csomagok[$kulcs])
                <div @class([
                    'flex h-full flex-col rounded-2xl p-8',
                    'border border-tinta-lagy bg-tinta-lagy' => ! $ajanlott,
                    'relative z-10 rounded-3xl border-2 border-blue-600 bg-blue-500 shadow-2xl shadow-blue-500/30 md:-translate-y-4' => $ajanlott,
                ])>
                    @if ($ajanlott)
                        <div class="absolute top-0 right-8 -translate-y-1/2">
                            <span class="rounded-full bg-mustar px-4 py-1.5 text-xs font-extrabold tracking-widest text-tinta uppercase shadow-lg">Ajánlott</span>
                        </div>
                    @endif

                    <h3 @class(['mb-2 font-extrabold', 'text-2xl text-white' => $ajanlott, 'text-xl text-vaszon' => ! $ajanlott])>{{ $cs['nev'] }}</h3>
                    <p @class(['mb-6 text-sm', 'text-white/80' => $ajanlott, 'text-vaszon/70' => ! $ajanlott])>{{ $leiras }}</p>

                    <div class="mb-6">
                        <span @class(['font-extrabold', 'text-5xl text-white' => $ajanlott, 'text-4xl' => ! $ajanlott])>
                            {{ \App\Support\Osszeg::formaz($cs['ar_havi']) }}
                        </span>
                        <span @class(['font-medium', 'text-white/80' => $ajanlott, 'text-vaszon/70' => ! $ajanlott])>
                            Ft / hó
                        </span>
                        {{-- Az ár egysége ott álljon, ahol a szám: egy előfizetés egy céget fed le. --}}
                        <div @class(['mt-1 text-sm', 'text-white/70' => $ajanlott, 'text-vaszon/60' => ! $ajanlott])>
                            cégenként, saját kerettel
                        </div>
                    </div>

                    <ul @class(['mb-8 flex-1 space-y-4 text-sm', 'text-white/90' => $ajanlott, 'text-vaszon/90' => ! $ajanlott])>
                        @foreach ([
                            '<strong>'.number_format((int) $cs['documents'], 0, ',', ' ').' dokumentum</strong> / hó',
                            '<strong>'.$cs['users'].' felhasználó</strong> ebben a cégben',
                            'Extra dokumentum: '.$cs['extra_ft'].' Ft',
                            'Feltöltés + saját beküldési e-mail cím',
                            'Számla, nyugta, külföldi bizonylat',
                            'E-számla XML modellhívás nélkül',
                            'Bizonytalan mezők megjelölése',
                            'Export: XLSX / CSV / JSON',
                        ] as $sor)
                            <li class="flex items-center gap-3">
                                <svg @class(['h-5 w-5 shrink-0', 'text-vaszon' => $ajanlott, 'text-mustar' => ! $ajanlott]) fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>{!! $sor !!}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('regisztracio') }}" @class([
                        'block w-full rounded-xl px-4 py-3 text-center font-bold transition-colors',
                        'bg-tinta text-vaszon hover:bg-slate-900' => ! $ajanlott,
                        'bg-vaszon py-4 text-lg font-extrabold text-blue-700 shadow-lg hover:bg-white' => $ajanlott,
                    ])>
                        {{ $ajanlott ? 'Kipróbálom '.$probaNapok.' napig' : 'Kiválasztom' }}
                    </a>
                </div>
            @endforeach

        </div>

        {{--
            A két szabály, amit előre ki kell mondani. Az egyik, hogy a keret
            elfogyása nem jelent automatikus továbbszámlázást; a másik, hogy a
            belső mérés oldalalapú — egy nyolcvan oldalas köteg nem egy nyugta.
            Amit az árlista elhallgat, azt a felhasználó a számlán tudja meg.
        --}}
        <div class="mx-auto mt-12 max-w-3xl space-y-3 text-center text-sm text-vaszon/60">
            <p>
                <strong class="text-vaszon/80">Az ár egy cégre szól</strong>, mert a keret is: minden cégnek
                saját havi darabszáma, saját beküldési e-mail címe és saját előfizetése van. Egy fiókban
                tetszőleges számú céget kezelhetsz — a fejlécben válthatsz köztük —, de mindegyikhez külön
                csomag tartozik. Ez a könyvelőiroda alapesete: ügyfelenként egy cég.
            </p>
            <p>
                A feltüntetett árak nettó árak, a keret minden csomagnál havi.
                Ha elfogy a havi keret, a feldolgozás
                <strong class="text-vaszon/80">alapból megáll</strong> — a beküldött iratok megvárják a következő
                időszakot. Darabonkénti továbbszámlázás csak akkor van, ha külön bekapcsolod, és akkor is
                <strong class="text-vaszon/80">az általad megadott forintos határig</strong>: váratlan számla
                nem érhet.
            </p>
            <p>
                Egy dokumentum a fair-use szabály szerint: {{ \App\Support\Kredit::szabaly() }}
                Egy számla vagy nyugta így egy dokumentum marad; egy vastag, összefűzött köteg többnek számít.
            </p>
        </div>
    </div>
</section>

<footer class="border-t border-zsalya/20 bg-slate-50 pt-16 pb-8">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col items-start justify-between gap-8 md:flex-row md:items-center">
            <div>
                <div class="mb-2 flex items-center gap-3">
                    <span class="flex h-8 w-8 -skew-x-6 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-blue-700 text-white shadow-md shadow-blue-500/30">
                        <x-logo class="h-5 w-5 text-white" :mono="true"/>
                    </span>
                    <span class="text-xl font-extrabold tracking-tight text-slate-800">Számla<span class="text-blue-700">Folyó</span></span>
                </div>
                <p class="max-w-xs text-sm leading-relaxed text-slate-600">
                    Dokumentumból ellenőrzött, könyvelésre kész adat — percek alatt.
                    Az adatok magyar szervereken tárolódnak.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-6 text-sm font-bold text-slate-600">
                <a href="{{ route('bejelentkezes') }}" class="transition-colors hover:text-blue-700">Bejelentkezés</a>
                <a href="{{ route('regisztracio') }}" class="transition-colors hover:text-blue-700">Regisztráció</a>
                <a href="mailto:{{ config('szamlafolyo.kapcsolat_email') }}" class="transition-colors hover:text-blue-700">Kapcsolat</a>
            </div>
        </div>

        <div class="border-t border-zsalya/20 pt-8">
            <p class="text-xs font-medium text-slate-500">© {{ date('Y') }} SzámlaFolyó. Minden jog fenntartva.</p>
        </div>
    </div>
</footer>

<x-fejlesztes-alatt/>
</body>
</html>
