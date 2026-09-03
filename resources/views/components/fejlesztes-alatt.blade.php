@if (config('szamlafolyo.fejlesztes_alatt'))
    {{--
        Figyelmeztetés a belépés előtti képernyőkön: az oldal él, de még nem
        kész. Natív <dialog>, nem keretrendszer — ez a látogató első
        találkozása az oldallal, és nem múlhat azon, betöltött-e egy szkript.

        Elvethető, mert a saját belépésünkhöz is át kell jutni rajta, és a
        munkamenetre megjegyezzük — különben minden oldalletöltésnél újra
        felugrana.
    --}}
    <dialog id="fejlesztes-alatt"
            class="max-w-md rounded-xl border border-slate-200 p-0 shadow-xl backdrop:bg-slate-900/40">
        <div class="p-6">
            <div class="mb-3 flex items-center gap-2">
                <x-logo class="h-6 w-6"/>
                <span class="font-semibold text-slate-900">SzámlaFolyó</span>
            </div>

            <h2 class="mb-2 text-base font-semibold text-slate-900">
                Az oldal fejlesztés alatt áll
            </h2>

            <p class="text-sm text-slate-600">
                Jelenleg még dolgozunk rajta, ezért a szolgáltatás nem érhető el
                teljeskörűen. Kérjük, nézzen vissza később.
            </p>

            <p class="mt-3 text-sm text-slate-600">
                További kérdéssel az
                <a href="mailto:{{ config('szamlafolyo.kapcsolat_email') }}"
                   class="font-medium text-blue-700 hover:underline">{{ config('szamlafolyo.kapcsolat_email') }}</a>
                e-mail címen érdeklődhet.
            </p>

            <form method="dialog" class="mt-6">
                <button value="ertettem" class="btn btn-primary w-full">Értem</button>
            </form>
        </div>
    </dialog>

    <script>
        (function () {
            const parbeszed = document.getElementById('fejlesztes-alatt');
            const kulcs = 'fejlesztes-alatt-lathatva';

            if (!parbeszed || typeof parbeszed.showModal !== 'function') {
                return;
            }

            // A munkamenetre megjegyezzük az elvetést. Ha a tároló nem érhető
            // el (privát ablak, letiltott sütik), inkább megmutatjuk újra,
            // mint hogy a szkript elszálljon és a gomb se működjön.
            let latta = false;

            try {
                latta = sessionStorage.getItem(kulcs) === '1';
            } catch (e) {
                latta = false;
            }

            if (!latta) {
                parbeszed.showModal();
            }

            parbeszed.addEventListener('close', function () {
                try {
                    sessionStorage.setItem(kulcs, '1');
                } catch (e) {
                    /* nem baj, csak újra felugrik */
                }
            });
        })();
    </script>
@endif
