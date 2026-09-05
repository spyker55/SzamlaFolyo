<div>
    @if ($this->nyitva())
        <h1 class="mb-1 text-lg font-semibold text-slate-900">Regisztráció</h1>
        <p class="mb-5 text-sm text-slate-500">
            {{ config('szamlafolyo.trial.days') }} nap próbaidő, {{ config('szamlafolyo.trial.documents') }} dokumentumig — kártya nélkül.
        </p>

        <form wire:submit="regisztracio" class="space-y-4">
            <div>
                <label class="flabel" for="nev">Név</label>
                <input id="nev" type="text" wire:model="nev" class="control" autocomplete="name" required autofocus>
                @error('nev') <p class="fhiba">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flabel" for="email">E-mail cím</label>
                <input id="email" type="email" wire:model="email" class="control" autocomplete="username" required>
                @error('email') <p class="fhiba">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flabel" for="jelszo">Jelszó</label>
                <input id="jelszo" type="password" wire:model="jelszo" class="control" autocomplete="new-password" required>
                @error('jelszo') <p class="fhiba">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="flabel" for="jelszo_megerosites">Jelszó még egyszer</label>
                <input id="jelszo_megerosites" type="password" wire:model="jelszo_megerosites" class="control" autocomplete="new-password" required>
            </div>

            {{-- Mindkét dokumentum új lapon nyílik: a félig kitöltött űrlapot
                 ne veszítse el az, aki elolvassa, mielőtt aláírja.

                 A két ige szándékosan más. Az ÁSZF szerződés, azt elfogadja az
                 ember; az adatkezelési tájékoztató nem szerződés, hanem
                 tájékoztatás — azt megismerni kell, nem elfogadni. --}}
            <div>
                <label class="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="feltetelek" class="mt-0.5 rounded border-slate-300">
                    <span>
                        Elfogadom az
                        <a href="{{ route('aszf') }}" target="_blank" rel="noopener"
                           class="font-medium text-blue-700 hover:underline">Általános Szerződési Feltételeket</a>,
                        és megismertem az
                        <a href="{{ route('adatkezeles') }}" target="_blank" rel="noopener"
                           class="font-medium text-blue-700 hover:underline">Adatkezelési tájékoztatót</a>.
                    </span>
                </label>
                @error('feltetelek') <p class="fhiba">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full">Fiók létrehozása</button>
        </form>
    @else
        <h1 class="mb-1 text-lg font-semibold text-slate-900">A regisztráció még zárva</h1>
        <p class="mb-5 text-sm text-slate-500">
            Az oldal jelenleg fejlesztés alatt áll, ezért új fiókot még nem lehet nyitni.
            Kérjük, nézzen vissza később.
        </p>

        <p class="text-sm text-slate-500">
            Ha addig kérdése van, írjon nekünk:
            <a href="mailto:{{ config('szamlafolyo.kapcsolat_email') }}"
               class="font-medium text-blue-700 hover:underline">{{ config('szamlafolyo.kapcsolat_email') }}</a>
        </p>
    @endif

    <p class="mt-5 text-center text-sm text-slate-500">
        Van már fiókod?
        <a href="{{ route('bejelentkezes') }}" wire:navigate class="font-medium text-blue-700 hover:underline">Bejelentkezem</a>
    </p>
</div>
