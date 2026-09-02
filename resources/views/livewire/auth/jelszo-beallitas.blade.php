<div>
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Új jelszó</h1>
    <p class="mb-5 text-sm text-slate-500">Add meg az új jelszavad.</p>

    <form wire:submit="beallit" class="space-y-4">
        <div>
            <label class="flabel" for="email">E-mail cím</label>
            <input id="email" type="email" wire:model="email" class="control" autocomplete="username" required>
            @error('email') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flabel" for="jelszo">Új jelszó</label>
            <input id="jelszo" type="password" wire:model="jelszo" class="control" autocomplete="new-password" required autofocus>
            @error('jelszo') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flabel" for="jelszo_megerosites">Új jelszó még egyszer</label>
            <input id="jelszo_megerosites" type="password" wire:model="jelszo_megerosites" class="control"
                   autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary w-full">Jelszó beállítása</button>
    </form>
</div>
