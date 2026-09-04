<div>
    <h1 class="mb-1 text-lg font-semibold text-slate-900">
        {{ $vanMarCege ? 'Új cég hozzáadása' : 'Cég létrehozása' }}
    </h1>
    <p class="mb-5 text-sm text-slate-500">
        Két adat, és indulhat a feltöltés.
        @if ($vanMarCege)
            Az elkészülte után rögtön erre a cégre váltunk; a többi megmarad, a fejlécben válthatsz köztük.
        @endif
    </p>

    @if ($vanMarCege)
        {{-- Ezt előre kell mondani, nem az első feltöltésnél kiderülnie. --}}
        <div class="alert alert-figyelem mb-5">
            <strong>Ez a cég nem kap próbaidőt.</strong>
            A próba a fiókodhoz tartozik, nem cégenként jár — a további cégekhez a
            Beállításokban kell csomagot választani, mielőtt bizonylatot dolgoznának fel.
        </div>
    @endif

    <form wire:submit="letrehoz" class="space-y-4">
        <div>
            <label class="flabel" for="nev">Cégnév</label>
            <input id="nev" type="text" wire:model="nev" class="control" required autofocus
                   placeholder="Példa Kereskedelmi Kft.">
            @error('nev') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flabel" for="adoszam">Adószám <span class="font-normal text-slate-400">(nem kötelező)</span></label>
            <input id="adoszam" type="text" wire:model="adoszam" class="control" placeholder="12345678-2-42">
            @error('adoszam') <p class="fhiba">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-400">
                Ebből tudja a rendszer, hogy egy bizonylaton te vagy a szállító vagy a vevő.
            </p>
        </div>

        <button type="submit" class="btn btn-primary w-full">Létrehozás</button>

        @if ($vanMarCege)
            <a href="{{ route('beerkezo') }}" wire:navigate
               class="block text-center text-sm text-slate-500 hover:text-slate-700">Mégse</a>
        @endif
    </form>
</div>
