<div>
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

        <button type="submit" class="btn btn-primary w-full">Fiók létrehozása</button>
    </form>

    <p class="mt-5 text-center text-sm text-slate-500">
        Van már fiókod?
        <a href="{{ route('bejelentkezes') }}" wire:navigate class="font-medium text-blue-700 hover:underline">Bejelentkezem</a>
    </p>
</div>
