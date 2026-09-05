{{--
    A három jogi oldal közös, ideiglenes törzse.

    Amíg a szöveg nincs meg, **nem írunk oda semmit**, ami jogi tartalomnak
    látszana: egy odavetett „mintaszöveg" rosszabb a hiányánál, mert a
    látogató elhiszi. Ennyi áll itt, és egy cím, ahol kérdezni lehet.

    A kitöltés: cseréld ki ezt a hívást az oldal saját szövegére a
    `resources/views/jogi/` alatt.
--}}
<div class="rounded-xl border border-dashed border-slate-300 bg-white/60 px-6 py-10 text-center">
    <p class="text-slate-700">{{ $mit }} jelenleg készül.</p>
    <p class="mt-2 text-slate-500">
        Amíg nem kerül ki, kérdésekre szívesen válaszolunk:
        <a href="mailto:{{ config('szamlafolyo.kapcsolat_email') }}"
           class="font-medium text-blue-700 hover:underline">{{ config('szamlafolyo.kapcsolat_email') }}</a>
    </p>
</div>
