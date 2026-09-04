<div class="max-w-3xl space-y-6">
    <x-uzenet :uzenet="$uzenet" :tipus="$uzenetTipus ?? 'siker'"/>
    <h1 class="text-xl font-semibold text-slate-900">Beállítások</h1>

    {{-- Beküldés e-mailben --}}
    <div class="card card-pad">
        <h2 class="mb-1 font-medium text-slate-900">Beküldés e-mailben</h2>
        <p class="mb-3 text-sm text-slate-500">
            Erre a címre továbbítva a számlát a melléklet magától bekerül a Beérkezőbe.
            A cím a cégedhez tartozik — aki ismeri, tud iratot beküldeni, ezért ne tedd közzé.
        </p>

        @if ($bekuldesiCim === '')
            <div class="alert alert-figyelem">
                A beküldési cím nincs beállítva ezen a kiszolgálón.
            </div>
        @else
            <input type="text" readonly value="{{ $bekuldesiCim }}"
                   onclick="this.select()"
                   class="control bg-slate-50 font-mono text-sm">

            @unless ($bekuldesAktiv)
                {{-- Ez a legfontosabb mondat ezen a kártyán: enélkül a cím
                     működőnek látszik, a rá küldött levél viszont sehova nem
                     érkezik meg, és a feladó sem kap hibát. --}}
                <div class="alert alert-figyelem mt-3">
                    <strong>A beérkeztetés még nincs bekapcsolva.</strong>
                    Az erre a címre küldött levelek jelenleg <strong>nem érkeznek meg</strong> —
                    a postafiók beállítása hiányzik a kiszolgálón. Addig töltsd fel az iratokat
                    a Beérkezőben.
                </div>
            @endunless

            <p class="mt-3 text-xs text-slate-400">
                E-mailből érkező irat soha nem kerül automatikusan jóváhagyásra — ellenőrzésre vár, mint minden más.
            </p>
        @endif
    </div>

    {{-- Előfizetés --}}
    <div class="card card-pad">
        <h2 class="mb-1 font-medium text-slate-900">Csomag és keret</h2>
        <p class="mb-4 text-sm text-slate-500">
            Jelenlegi csomag: <strong>{{ $ceg->csomagNeve() }}</strong>
            @if ($ceg->probaidosE())
                — a próbaidő {{ \App\Support\Ido::datum($ceg->trial_ends_at) }} napon jár le.
            @endif
        </p>

        @php($korlatlan = $keret === PHP_INT_MAX)
        <div class="mb-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">
            Felhasználva: <strong>{{ $felhasznalt }}</strong>
            @unless ($korlatlan) / {{ $keret }} dokumentum @endunless
            @if (! $korlatlan && $keret > 0)
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                    <div class="h-full bg-blue-600" style="width: {{ min(100, (int) round($felhasznalt / $keret * 100)) }}%"></div>
                </div>
            @endif
        </div>

        @error('elofizetes') <div class="alert alert-hiba mb-3">{{ $message }}</div> @enderror

        @if ($sajatSzerep?->adminisztralhat())
            <div class="flex flex-wrap gap-2">
                @foreach ($csomagok as $kulcs => $csomag)
                    <button wire:click="elofizetes('{{ $kulcs }}')"
                            class="btn {{ $ceg->stripe_price_id === ($csomag['price_id'] ?? null) ? 'btn-primary' : 'btn-secondary' }}">
                        {{ $csomag['nev'] }} — {{ $csomag['documents'] }} db/hó
                    </button>
                @endforeach
                @if ($ceg->stripe_customer_id)
                    <button wire:click="portal" class="btn btn-ghost">Számlázás kezelése</button>
                @endif
            </div>
        @else
            <p class="text-xs text-slate-400">A csomagot a cég tulajdonosa tudja módosítani.</p>
        @endif
    </div>

    {{-- Cégadatok --}}
    <div class="card card-pad">
        <h2 class="mb-3 font-medium text-slate-900">Cégadatok</h2>
        <form wire:submit="cegMentes" class="space-y-4">
            <div>
                <label class="flabel" for="cegNev">Cégnév</label>
                <input id="cegNev" type="text" wire:model="cegNev" class="control"
                       @disabled(! $sajatSzerep?->adminisztralhat())>
                @error('cegNev') <p class="fhiba">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="flabel" for="cegAdoszam">Adószám</label>
                <input id="cegAdoszam" type="text" wire:model="cegAdoszam" class="control"
                       @disabled(! $sajatSzerep?->adminisztralhat())>
                @error('cegAdoszam') <p class="fhiba">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="flabel" for="megorzesiNapok">Eredeti fájlok megőrzése export után (nap)</label>
                <input id="megorzesiNapok" type="number" min="0" max="{{ \App\Models\Company::MEGORZES_MAX_NAP }}"
                       wire:model="megorzesiNapok" class="control w-32"
                       @disabled(! $sajatSzerep?->adminisztralhat())>
                <p class="mt-1 text-xs text-slate-400">
                    0 = az exporttal egy időben törlődnek. A tárhely véges, ezért ez az alapérték.
                    Legfeljebb {{ \App\Models\Company::MEGORZES_MAX_NAP }} nap: a kiolvasott adat az
                    exportban van, az eredeti fájlt utána nincs miért őrizni.
                </p>
                @error('megorzesiNapok') <p class="fhiba">{{ $message }}</p> @enderror
            </div>
            @if ($sajatSzerep?->adminisztralhat())
                <button type="submit" class="btn btn-primary">Mentés</button>
            @endif
        </form>
    </div>

    {{-- Tagok --}}
    <div class="card card-pad">
        <h2 class="mb-3 font-medium text-slate-900">Felhasználók</h2>

        <table class="tbl mb-4">
            <tbody>
            @foreach ($tagok as $tag)
                <tr class="trow" wire:key="tag-{{ $tag->id }}">
                    <td class="td">
                        <div class="font-medium text-slate-900">{{ $tag->name }}</div>
                        <div class="text-xs text-slate-500">{{ $tag->email }}</div>
                    </td>
                    <td class="td">
                        <span class="badge badge-semleges">
                            {{ \App\Enums\Szerep::tryFrom($tag->pivot->role)?->cimke() ?? $tag->pivot->role }}
                        </span>
                    </td>
                    <td class="td text-right">
                        @if ($sajatSzerep?->adminisztralhat() && $tag->id !== auth()->id())
                            <button wire:click="tagEltavolitas({{ $tag->id }})"
                                    wire:confirm="Biztosan eltávolítod?"
                                    class="btn btn-ghost btn-sm text-red-700">Eltávolítás</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($sajatSzerep?->adminisztralhat())
            <form wire:submit="tagFelvetel" class="flex flex-wrap items-end gap-3">
                <div class="min-w-56 flex-1">
                    <label class="flabel" for="ujTagEmail">Új felhasználó e-mail címe</label>
                    <input id="ujTagEmail" type="email" wire:model="ujTagEmail" class="control">
                    @error('ujTagEmail') <p class="fhiba">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="flabel" for="ujTagSzerep">Szerep</label>
                    <select id="ujTagSzerep" wire:model="ujTagSzerep" class="control w-40">
                        @foreach ($szerepek as $ertek => $cimke)
                            <option value="{{ $ertek }}">{{ $cimke }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Hozzáadás</button>
            </form>
        @endif
    </div>

    {{-- Tárhely --}}
    <div class="card card-pad">
        <h2 class="mb-1 font-medium text-slate-900">Tárhely</h2>
        <p class="text-sm text-slate-600">
            A cég fájljai jelenleg <strong>{{ number_format($tarhelyBajt / 1048576, 1, ',', ' ') }} MB</strong> helyet foglalnak.
        </p>
        <p class="mt-1 text-xs text-slate-400">
            Az eredeti bizonylatok az export után törlődnek (vagy a megadott megőrzési idő letelte után),
            ezért ez a szám nem nő korlátlanul.
        </p>
    </div>
</div>
