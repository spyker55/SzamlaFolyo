<div>
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Cég létrehozása</h1>
    <p class="mb-5 text-sm text-slate-500">Két adat, és indulhat a feltöltés.</p>

    <form wire:submit="letrehoz" class="space-y-4">
        <div>
            <label class="flabel" for="nev">Cégnév</label>
            <input id="nev" type="text" wire:model="nev" class="control" required autofocus
                   placeholder="Példa Kereskedelmi Kft.">
            @error('nev') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flabel" for="adoszam">Adószám</label>
            <input id="adoszam" type="text" wire:model="adoszam" class="control" required
                   placeholder="12345678-2-42">
            @error('adoszam') <p class="fhiba">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-slate-400">
                Ebből tudja a rendszer, hogy egy bizonylaton te vagy a szállító vagy a vevő.
                A SzámlaFolyót vállalkozások használhatják, ezért kötelező.
            </p>
        </div>

        <button type="submit" class="btn btn-primary w-full">Létrehozás</button>
    </form>
</div>
