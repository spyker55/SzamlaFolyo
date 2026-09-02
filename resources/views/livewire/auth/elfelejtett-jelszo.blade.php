<div>
    <x-uzenet :uzenet="$uzenet" :tipus="$uzenetTipus ?? 'siker'"/>
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Elfelejtett jelszó</h1>
    <p class="mb-5 text-sm text-slate-500">
        Add meg az e-mail címed, és küldünk egy linket, amivel új jelszót állíthatsz be.
    </p>

    <form wire:submit="kuldes" class="space-y-4">
        <div>
            <label class="flabel" for="email">E-mail cím</label>
            <input id="email" type="email" wire:model="email" class="control" autocomplete="username" required autofocus>
            @error('email') <p class="fhiba">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full">Link küldése</button>
    </form>

    <p class="mt-5 text-center text-sm text-slate-500">
        <a href="{{ route('bejelentkezes') }}" wire:navigate class="font-medium text-blue-700 hover:underline">
            Vissza a bejelentkezéshez
        </a>
    </p>
</div>
