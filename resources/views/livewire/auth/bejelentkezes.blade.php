<div>
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Bejelentkezés</h1>
    <p class="mb-5 text-sm text-slate-500">Folytasd ott, ahol abbahagytad.</p>

    <form wire:submit="bejelentkezes" class="space-y-4">
        <div>
            <label class="flabel" for="email">E-mail cím</label>
            <input id="email" type="email" wire:model="email" class="control" autocomplete="username" required autofocus>
            @error('email') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="flabel" for="jelszo">Jelszó</label>
            <input id="jelszo" type="password" wire:model="jelszo" class="control" autocomplete="current-password" required>
            @error('jelszo') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="emlekezz" class="rounded border-slate-300">
                Maradjak bejelentkezve
            </label>
            <a href="{{ route('password.request') }}" wire:navigate class="text-sm text-blue-700 hover:underline">
                Elfelejtettem
            </a>
        </div>

        <button type="submit" class="btn btn-primary w-full">
            <span wire:loading.remove wire:target="bejelentkezes">Bejelentkezés</span>
            <span wire:loading wire:target="bejelentkezes">Egy pillanat…</span>
        </button>
    </form>

    <p class="mt-5 text-center text-sm text-slate-500">
        Még nincs fiókod?
        <a href="{{ route('regisztracio') }}" wire:navigate class="font-medium text-blue-700 hover:underline">Regisztrálok</a>
    </p>
</div>
