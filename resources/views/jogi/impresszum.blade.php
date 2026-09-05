{{--
    Az üzemeltető azonosító adatai. A kapcsolattartási cím a configból jön
    (`KAPCSOLAT_EMAIL`), mert az impresszumban szereplő cím nem lehet dísz:
    ha egyszer átállítjuk, az itt közölt is kövesse.

    Ami **szándékosan nincs** benne: az EU online vitarendezési platformjának
    (ODR) linkje. A platform az (EU) 2024/3228 rendelet nyomán 2025. július
    20-án megszűnt — a magyar impresszum-sablonok javában máig ott van, és
    onnan másolva kerülne ide is.

    A `[&>dt:not(:first-child)]:mt-3` a mobil nézetért van: egy hasábban a
    címke az értéke fölé csúszik, és egyenletes térközzel a párok
    összemosódnának. `sm:` fölött két hasáb van, ott nem kell — a felülírás
    ezért **ugyanazzal a szelektorral** nulláz (`sm:[&>dt:not(:first-child)]`).
    Egy sima `sm:[&>dt]:mt-0` nem érne el semmit: a `:not()` specifikusabb,
    és a specificitás erősebb a forrás sorrendjénél — a címkék elcsúsznának
    a saját értéküktől.
--}}
<x-layouts.jogi cim="Impresszum">

    <h2 class="text-base font-semibold text-slate-900">A szolgáltató</h2>

    <dl class="grid grid-cols-1 gap-x-6 gap-y-1 [&>dt:not(:first-child)]:mt-3 sm:grid-cols-[13rem_1fr] sm:gap-y-2 sm:[&>dt:not(:first-child)]:mt-0">
        <dt class="text-slate-500">Név</dt>
        <dd class="font-medium text-slate-900">Nyeste Krisztián egyéni vállalkozó</dd>

        <dt class="text-slate-500">Székhely</dt>
        <dd>3000 Hatvan, István király utca 7.</dd>

        <dt class="text-slate-500">Nyilvántartásba vevő hatóság</dt>
        <dd>Nemzeti Adó- és Vámhivatal (NAV)</dd>

        <dt class="text-slate-500">Nyilvántartási szám</dt>
        <dd>62574956</dd>

        <dt class="text-slate-500">Adószám</dt>
        <dd>92220155-1-30</dd>

        <dt class="text-slate-500">Kamarai regisztráció</dt>
        <dd>Heves Vármegyei Kereskedelmi és Iparkamara (HKIK)<br>3300 Eger, Faiskola út 15.</dd>

        <dt class="text-slate-500">E-mail</dt>
        <dd>
            <a href="mailto:{{ config('szamlafolyo.kapcsolat_email') }}"
               class="font-medium text-blue-700 hover:underline">{{ config('szamlafolyo.kapcsolat_email') }}</a>
        </dd>

        <dt class="text-slate-500">Telefon</dt>
        <dd><a href="tel:+36706043043" class="font-medium text-blue-700 hover:underline">+36 70 604 3043</a></dd>

        <dt class="text-slate-500">Weboldal</dt>
        <dd>szamlafolyo.hu</dd>
    </dl>

    <h2 class="pt-4 text-base font-semibold text-slate-900">A szolgáltatás</h2>

    <p>
        A SzámlaFolyó a szamlafolyo.hu címen elérhető online szolgáltatás: bejövő számlákat és
        bizonylatokat olvas ki gépi úton, és könyvelésre alkalmas formában ad tovább.
    </p>

    <p>
        A szolgáltatást kizárólag vállalkozások vehetik igénybe. A használat feltételeit az
        <a href="{{ route('aszf') }}" class="font-medium text-blue-700 hover:underline">ÁSZF</a>,
        a személyes adatok kezelését az
        <a href="{{ route('adatkezeles') }}" class="font-medium text-blue-700 hover:underline">Adatkezelési tájékoztató</a>
        tartalmazza.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">Tárhelyszolgáltató</h2>

    <dl class="grid grid-cols-1 gap-x-6 gap-y-1 [&>dt:not(:first-child)]:mt-3 sm:grid-cols-[13rem_1fr] sm:gap-y-2 sm:[&>dt:not(:first-child)]:mt-0">
        <dt class="text-slate-500">Név</dt>
        <dd class="font-medium text-slate-900">Nethely Kft.</dd>

        <dt class="text-slate-500">Székhely</dt>
        <dd>1115 Budapest, Halmi utca 29.</dd>

        <dt class="text-slate-500">Cégjegyzékszám</dt>
        <dd>01-09-961790</dd>

        <dt class="text-slate-500">E-mail</dt>
        <dd>
            <a href="mailto:info@nethely.hu" class="font-medium text-blue-700 hover:underline">info@nethely.hu</a>
        </dd>
    </dl>

    <p>A szolgáltatás kiszolgálói és adatbázisa Magyarországon üzemelnek.</p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">Panasz</h2>

    <p>
        Panaszt a fenti e-mail címen lehet bejelenteni. A panaszt megvizsgáljuk, és legkésőbb
        harminc napon belül írásban válaszolunk. Mivel a szolgáltatást kizárólag vállalkozások
        vehetik igénybe, fogyasztói békéltető testületi eljárásnak nincs helye; a vitákra
        egyebekben az ÁSZF rendelkezései irányadók.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">Szerzői jog</h2>

    <p>
        A szamlafolyo.hu oldalon megjelenő tartalom, a SzámlaFolyó név, a logó és a szolgáltatást
        működtető szoftver a szolgáltató szellemi tulajdona. Felhasználásukhoz előzetes írásbeli
        engedély szükséges.
    </p>

</x-layouts.jogi>
