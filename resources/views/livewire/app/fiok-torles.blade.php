{{--
    A fiók törlése.

    A képernyő egyetlen dolga, hogy a felhasználó a gomb megnyomása **előtt**
    tudja, mi fog történni — ezért a szöveg nem általánosságban beszél, hanem
    a saját helyzetéről: hány bizonylat, van-e előfizetés, marad-e a cég.
    A számok a `TorlesTerv`-ből jönnek, ugyanabból, ami a törlést végrehajtja.
--}}
<div class="max-w-2xl space-y-6">
    <div>
        <a href="{{ route('beallitasok') }}" wire:navigate class="text-sm text-blue-700 hover:underline">
            ← Vissza a Beállításokhoz
        </a>
        <h1 class="mt-2 text-xl font-semibold text-slate-900">Fiók törlése</h1>
    </div>

    @if ($terv->akadaly !== null)
        {{-- Az akadály nem hiba, hanem feladat: a felhasználó tudja feloldani,
             ezért a mondat megmondja, mit csináljon, és a jelszómező el sem
             jelenik meg — ne kelljen kipróbálnia, hogy nem működik. --}}
        <div class="alert alert-figyelem">
            {{ $terv->akadaly->getMessage() }}
        </div>

        <a href="{{ route('beallitasok') }}" wire:navigate class="btn btn-secondary">
            Felhasználók kezelése
        </a>
    @else
        <div class="card card-pad space-y-4">
            <h2 class="font-medium text-slate-900">Mi történik, ha törlöd</h2>

            @if ($terv->ceg === null)
                {{-- Regisztrált, de céget sosem hozott létre. Nincs mit
                     elveszítenie, és nincs is miről beszámolni — a cégre
                     szabott mondatok itt mind hazudnának. --}}
                <p class="text-sm text-slate-600">
                    A fiókodhoz nem tartozik cég, ezért csak a bejelentkezési fiókod szűnik meg.
                    Nincs tárolt bizonylatod és nincs előfizetésed.
                </p>
            @elseif ($terv->cegIsTorlodik)
                <p class="text-sm text-slate-600">
                    Te vagy az egyetlen felhasználó a(z) <strong>{{ $terv->ceg->name }}</strong>
                    cégben, ezért a fiókoddal együtt a cég is megszűnik.
                </p>

                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
                    <li>
                        <strong>{{ $terv->iratok }}</strong> bizonylat, a hozzájuk tartozó kiolvasott
                        adatokkal és exportokkal együtt, véglegesen törlődik.
                    </li>
                    <li>A feltöltött eredeti fájlok törlődnek a szerverről.</li>
                    @if ($terv->vanElofizetes)
                        <li>
                            <strong>Az előfizetést azonnal lemondjuk.</strong> A kifizetett időszak
                            hátralévő része nem használható fel, és nem téríthető vissza.
                        </li>
                    @endif
                    <li>
                        A már kiállított számláink megmaradnak: azokat számviteli előírás alapján meg
                        kell őriznünk. Erről az
                        <a href="{{ route('adatkezeles') }}" class="font-medium text-blue-700 hover:underline">Adatkezelési tájékoztató</a>
                        szól bővebben.
                    </li>
                </ul>

                <div class="alert alert-figyelem">
                    <strong>Ezt nem lehet visszavonni, és nincs mögötte mentés.</strong>
                    Ha kellenek az adatok, előbb
                    <a href="{{ route('export') }}" wire:navigate class="font-medium underline">készíts exportot</a>,
                    és töltsd le.
                </div>
            @else
                <p class="text-sm text-slate-600">
                    Rajtad kívül még <strong>{{ $terv->maradoTagok }}</strong> felhasználó dolgozik
                    a(z) <strong>{{ $terv->ceg->name }}</strong> cégben, ezért csak a te fiókod
                    szűnik meg.
                </p>

                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
                    <li>A cég bizonylatai, exportjai és beállításai megmaradnak.</li>
                    <li>Az előfizetés érintetlen marad — azt a cég tulajdonosa mondhatja le.</li>
                    <li>Te ezután nem tudsz belépni, és a cég adataihoz nem férsz hozzá.</li>
                </ul>
            @endif
        </div>

        <form wire:submit="torles" class="card card-pad space-y-4">
            <div>
                <label class="flabel" for="jelszo">A megerősítéshez add meg a jelszavad</label>
                <input id="jelszo" type="password" wire:model="jelszo" class="control"
                       autocomplete="current-password">
                <p class="mt-1 text-xs text-slate-400">
                    Fiókot csak az szüntethessen meg, aki bizonyítani tudja, hogy az övé. Ha meghívóval
                    kerültél be, és nincs jelszavad, a bejelentkezésnél az „Elfelejtettem” linkkel tudsz
                    beállítani egyet.
                </p>
                @error('jelszo') <p class="fhiba">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-danger"
                        wire:confirm="Ezt nem lehet visszavonni. Biztosan törlöd a fiókod?">
                    <span wire:loading.remove wire:target="torles">Fiók végleges törlése</span>
                    <span wire:loading wire:target="torles">Törlés…</span>
                </button>
                <a href="{{ route('beallitasok') }}" wire:navigate class="btn btn-ghost">Mégsem</a>
            </div>
        </form>
    @endif
</div>
