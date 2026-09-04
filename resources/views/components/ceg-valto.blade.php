@php
    $felhasznalo = auth()->user();
    $cegek = $felhasznalo?->cegei() ?? collect();
    $aktiv = app(\App\Support\Berlo::class)->ceg();
@endphp

@if ($felhasznalo !== null && $aktiv !== null)
    <div x-data="{ nyitva: false }" @keydown.escape.window="nyitva = false" class="relative">
        <button type="button"
                @click="nyitva = ! nyitva"
                :aria-expanded="nyitva ? 'true' : 'false'"
                aria-haspopup="menu"
                class="flex max-w-52 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium
                       text-slate-900 hover:bg-slate-100">
            <span class="truncate">{{ $aktiv->nevRovid() }}</span>
            @if ($cegek->count() > 1)
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-500">
                    {{ $cegek->count() }}
                </span>
            @endif
            <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="nyitva" x-cloak @click.outside="nyitva = false" role="menu"
             class="absolute right-0 z-30 mt-2 w-72 overflow-hidden rounded-xl border border-slate-200
                    bg-white py-1 shadow-lg">
            <p class="px-3 py-2 text-xs text-slate-400">Bejelentkezve: {{ $felhasznalo->email }}</p>

            @foreach ($cegek as $ceg)
                @php($ez = $ceg->id === $aktiv->id)
                <form method="POST" action="{{ route('ceg.valtas') }}" role="none">
                    @csrf
                    <input type="hidden" name="ceg" value="{{ $ceg->id }}">
                    {{--
                        Az aktív cég gombja tiltott, nem elrejtett: a lista így
                        megmutatja, hol állsz, és a fölösleges újratöltést is
                        megspórolja.
                    --}}
                    <button type="submit" role="menuitem" @disabled($ez)
                            class="flex w-full items-start gap-2 px-3 py-2 text-left text-sm
                                   {{ $ez ? 'bg-slate-50' : 'hover:bg-slate-50' }}">
                        <span class="mt-0.5 w-4 shrink-0 text-blue-700">
                            @if ($ez)
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5"
                                     viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            @endif
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-slate-900">{{ $ceg->name }}</span>
                            <span class="block text-xs text-slate-500">
                                {{ \App\Enums\Szerep::tryFrom((string) $ceg->pivot->role)?->cimke() ?? $ceg->pivot->role }}
                            </span>
                        </span>
                    </button>
                </form>
            @endforeach

            <div class="mt-1 border-t border-slate-100 pt-1">
                <a href="{{ route('ceg.letrehozas') }}" wire:navigate role="menuitem"
                   class="block px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">+ Új cég hozzáadása</a>
            </div>
        </div>
    </div>
@endif
