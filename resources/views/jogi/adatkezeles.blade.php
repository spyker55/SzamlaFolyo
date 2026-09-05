{{--
    Adatkezelési tájékoztató.

    Ez a három jogi oldal közül a legtényszerűbb: minden mondatának egy
    konkrét kódrészlet felel meg. Ha az adatáramlás változik — új
    adatfeldolgozó, más modell, más megőrzési idő —, ezt a lapot **ugyanabban
    a commitban** kell módosítani, mint a kódot.

    A számok itt is a configból jönnek (megőrzési plafon, postafiók-takarítás),
    hogy ne csússzanak el attól, amit a `fajl:selejtez` ténylegesen csinál.

    Két szerepkör van, és ezt nem szabad összemosni: a **fiók** adataira nézve
    a Szolgáltató adatkezelő, a **feltöltött bizonylatokra** nézve az Előfizető
    az adatkezelő és a Szolgáltató az adatfeldolgozó. Az ÁSZF 11. pontja
    ugyanezt mondja — a kettőnek egyeznie kell.

    Jogi felülvizsgálaton nem esett át.
--}}
@php
    $hatalyos = '2026. szeptember 5.';

    $megorzesMax = \App\Models\Company::MEGORZES_MAX_NAP;
    // Configból, nem `env()`-ből: a nézetben hívott `env()` `config:cache`
    // után `null`-t ad, és a tájékoztató csendben nulla napot ígérne.
    $postafiokNap = (int) config('inbox.imap.keep_days');
    $besorolatlanNap = (int) config('inbox.imap.unmatched_keep_days');
    $modell = (string) config('openrouter.model');
    $email = config('szamlafolyo.kapcsolat_email');
@endphp
<x-layouts.jogi cim="Adatkezelési tájékoztató">

    <p class="text-slate-500">Hatályos: {{ $hatalyos }}</p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">1. Ki kezeli az adatokat, és milyen szerepben</h2>

    <p>
        A SzámlaFolyó szolgáltatást <strong>Nyeste Krisztián egyéni vállalkozó</strong> üzemelteti; azonosító
        adatai és elérhetőségei az
        <a href="{{ route('impresszum') }}" class="font-medium text-blue-700 hover:underline">Impresszumban</a>
        találhatók. Adatvédelmi kérdésekben a
        <a href="mailto:{{ $email }}" class="font-medium text-blue-700 hover:underline">{{ $email }}</a>
        címen lehet hozzá fordulni.
    </p>

    <p>Két, egymástól elkülönülő szerepkör van, és ezt érdemes az elején tisztázni:</p>

    <ul class="list-disc space-y-1 pl-5">
        <li>
            <strong>A fiók adataira nézve a Szolgáltató az adatkezelő.</strong> Ide tartozik a regisztráló
            neve, e-mail címe, a cég neve és adószáma, valamint az előfizetés adatai.
        </li>
        <li>
            <strong>A feltöltött bizonylatokra nézve az Előfizető az adatkezelő</strong>, a Szolgáltató
            pedig adatfeldolgozó. A bizonylatokon szereplő adatok — köztük személyes adatok, ha a partner
            egyéni vállalkozó vagy magánszemély — az Előfizető birtokában lévő iratokból származnak. A
            Szolgáltató ezeket kizárólag a Szolgáltatás nyújtása érdekében, az Előfizető utasításai
            szerint kezeli: nem elemzi más célra, nem adja tovább, és a szerződés megszűnésekor törli.
        </li>
    </ul>

    <h2 class="pt-4 text-base font-semibold text-slate-900">2. Milyen adatokat kezelünk</h2>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[38rem] text-left">
            <thead>
                <tr class="border-b border-slate-300 text-slate-500">
                    <th class="py-2 pr-4 font-medium">Mit</th>
                    <th class="py-2 pr-4 font-medium">Miért</th>
                    <th class="py-2 pr-4 font-medium">Jogalap</th>
                    <th class="py-2 font-medium">Meddig</th>
                </tr>
            </thead>
            <tbody class="align-top">
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">Név, e-mail cím, titkosított jelszó</td>
                    <td class="py-2 pr-4">Fiók, belépés, értesítések</td>
                    <td class="py-2 pr-4">Szerződés teljesítése</td>
                    <td class="py-2">A szerződés megszűnéséig</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">Cégnév, adószám</td>
                    <td class="py-2 pr-4">A cég azonosítása, jogosultság a szolgáltatásra</td>
                    <td class="py-2 pr-4">Szerződés teljesítése</td>
                    <td class="py-2">A szerződés megszűnéséig</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">Előfizetés és fizetés adatai (Stripe-azonosító, állapot, időszak)</td>
                    <td class="py-2 pr-4">Számlázás, keretszámítás</td>
                    <td class="py-2 pr-4">Szerződés teljesítése, illetve jogi kötelezettség</td>
                    <td class="py-2">A számviteli előírások szerinti megőrzési ideig</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">Feltöltött bizonylatok és a belőlük kiolvasott adatok</td>
                    <td class="py-2 pr-4">A Szolgáltatás nyújtása</td>
                    <td class="py-2 pr-4">Az Előfizető utasítása (adatfeldolgozás)</td>
                    <td class="py-2">Lásd a 4. pontot</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">Beérkező levelek adatai (feladó címe, tárgy, időpont)</td>
                    <td class="py-2 pr-4">A beküldés visszakövethetősége, kétszeres feldolgozás elkerülése</td>
                    <td class="py-2 pr-4">Szerződés teljesítése</td>
                    <td class="py-2">A szerződés megszűnéséig</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">A visszafordíthatatlan műveletek naplója (export, törlés, tag felvétele)</td>
                    <td class="py-2 pr-4">Utólagos visszakövethetőség a cégen belül</td>
                    <td class="py-2 pr-4">Jogos érdek: elszámoltathatóság</td>
                    <td class="py-2">A szerződés megszűnéséig</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">IP-cím a belépési és jelszó-emlékeztető kísérletekhez</td>
                    <td class="py-2 pr-4">Próbálgatásos támadás elleni védelem</td>
                    <td class="py-2 pr-4">Jogos érdek: a fiókok biztonsága</td>
                    <td class="py-2">Legfeljebb egy óra, csak a gyorsítótárban</td>
                </tr>
                <tr>
                    <td class="py-2 pr-4">Munkamenet-süti</td>
                    <td class="py-2 pr-4">Bejelentkezett állapot fenntartása</td>
                    <td class="py-2 pr-4">A szolgáltatáshoz feltétlenül szükséges</td>
                    <td class="py-2">A böngésző bezárásáig, illetve a munkamenet lejártáig</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p>
        <strong>Sütiket mérésre vagy hirdetésre nem használunk.</strong> A weboldalon nincs látogatásmérő,
        nincs hirdetési kódrészlet, és nincs profilalkotás. Ezért süti-hozzájáruló ablak sem fogadja a
        látogatót: nincs mihez hozzájárulni.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">3. Mi történik egy feltöltött bizonylattal</h2>

    <p>Ez a tájékoztató legfontosabb szakasza, mert itt hagyják el az adatok a szervert.</p>

    <ol class="list-decimal space-y-2 pl-5">
        <li>
            A bizonylat a böngészőből vagy a cég beküldési e-mail címére érkezik, és a magyarországi
            kiszolgálón tárolódik.
        </li>
        <li>
            A kiolvasáshoz a bizonylat tartalma — a PDF vagy a kép — <strong>elhagyja a szervert</strong>:
            az OpenRouter szolgáltatáson keresztül eljut a kiolvasást végző mesterséges intelligencia
            modellhez (jelenleg: <code class="rounded bg-slate-100 px-1">{{ $modell }}</code>). A
            modellnek a bizonylat mellett a saját cég adószámát küldjük el, hogy tudja, melyik oldalon
            állunk. <strong>Felhasználói nevet, e-mail címet, jelszót nem küldünk.</strong>
        </li>
        <li>
            Az e-számla XML feldolgozása <strong>modellhívás nélkül</strong> történik: az ilyen irat
            tartalma nem hagyja el a szervert.
        </li>
        <li>
            A kiolvasott adat az ellenőrző képernyőre kerül, ahol az Előfizető átnézi és javítja. A modell
            nyers válasza és a javítások külön tárolódnak — ebből mérhető, mennyit hibázik a gépi
            kiolvasás.
        </li>
        <li>
            Az export elkészültével <strong>az eredeti fájlok törlődnek</strong> a szerverről.
            Alapesetben azonnal; a cég tulajdonosa legfeljebb {{ $megorzesMax }} napos türelmi időt
            állíthat be. A kiolvasott adatok megmaradnak.
        </li>
    </ol>

    <h2 class="pt-4 text-base font-semibold text-slate-900">4. Meddig őrizzük az adatokat</h2>

    <ul class="list-disc space-y-1 pl-5">
        <li>
            <strong>Eredeti fájlok:</strong> az export után törlődnek, legfeljebb {{ $megorzesMax }} napos,
            cégenként állítható türelmi idővel.
        </li>
        <li>
            <strong>A beküldési postafiók levelei:</strong> a feldolgozottak legfeljebb
            {{ $postafiokNap }} napig, a besorolhatatlanok legfeljebb {{ $besorolatlanNap }} napig
            maradnak meg, azután törlődnek. Enélkül a levélben álló melléklet a törölt fájl teljes
            másolataként élne tovább.
        </li>
        <li>
            <strong>Kiolvasott és jóváhagyott adatok, fiókadatok:</strong> a szerződés megszűnéséig, azt
            követően ésszerű időn belül törölve.
        </li>
        <li>
            <strong>Számlázási adatok:</strong> a számviteli előírások szerinti megőrzési ideig — ezt
            jogszabály írja elő, törlési kérésre sem szüntethető meg.
        </li>
    </ul>

    <p>
        A bizonylatok saját, jogszabályi megőrzéséről az Előfizetőnek kell gondoskodnia; a Szolgáltató
        általi törlés ezt a kötelezettséget nem teljesíti és nem helyettesíti.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">5. Kik férnek hozzá — adatfeldolgozók</h2>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[34rem] text-left">
            <thead>
                <tr class="border-b border-slate-300 text-slate-500">
                    <th class="py-2 pr-4 font-medium">Ki</th>
                    <th class="py-2 pr-4 font-medium">Mit csinál</th>
                    <th class="py-2 font-medium">Hol</th>
                </tr>
            </thead>
            <tbody class="align-top">
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">Nethely Kft.</td>
                    <td class="py-2 pr-4">Tárhely, adatbázis, levelezés</td>
                    <td class="py-2">Magyarország</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">OpenRouter, Inc.</td>
                    <td class="py-2 pr-4">A kiolvasási kérés továbbítása a modellhez</td>
                    <td class="py-2">Amerikai Egyesült Államok</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2 pr-4">A kiolvasást végző modell szolgáltatója</td>
                    <td class="py-2 pr-4">A bizonylat gépi kiolvasása</td>
                    <td class="py-2">Amerikai Egyesült Államok</td>
                </tr>
                <tr>
                    <td class="py-2 pr-4">Stripe</td>
                    <td class="py-2 pr-4">Bankkártyás fizetés, előfizetés-kezelés</td>
                    <td class="py-2">Írország / Amerikai Egyesült Államok</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p>
        A kiszolgálók, az adatbázis és a levelezés Magyarországon üzemelnek. A kiolvasás és a fizetés
        viszont az Európai Unión kívülre továbbítással jár. Ezekre az Európai Bizottság
        megfelelőségi határozata, illetve — ahol az nem alkalmazható — az Európai Bizottság által
        elfogadott általános szerződési feltételek adnak jogalapot.
    </p>

    <p>
        A Szolgáltató bankkártyaadatot nem lát és nem tárol: azt a Stripe kezeli a saját felületén.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">6. Betűtípusok</h2>

    <p>
        Az oldal betűtípusait a Google Fonts szolgáltatásból tölti be. Emiatt az oldal megnyitásakor a
        látogató IP-címe és böngészőjének adatai eljutnak a Google-höz. A megjelenített szöveg
        rendszerbetűvel akkor is olvasható marad, ha ez a betöltés elmarad.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">7. Adatbiztonság</h2>

    <ul class="list-disc space-y-1 pl-5">
        <li>A kapcsolat titkosított (HTTPS), a jelszavak visszafejthetetlen formában tárolódnak.</li>
        <li>
            A cégek adatai el vannak különítve egymástól: minden lekérdezés a belépett felhasználó cégére
            szűkül, ezt a szűkítést nem lehet megkerülni a felületről.
        </li>
        <li>
            A beküldési e-mail cím kitalálhatatlan tokent tartalmaz; a beérkezett levelet mindig a
            <em>címzett</em> alapján soroljuk céghez, soha nem a feladó alapján.
        </li>
        <li>Az e-mailben érkezett irat soha nem kerül automatikusan jóváhagyásra.</li>
    </ul>

    <h2 class="pt-4 text-base font-semibold text-slate-900">8. Az érintett jogai</h2>

    <p>
        Az érintett kérheti a rá vonatkozó adatokhoz való hozzáférést, azok helyesbítését, törlését vagy
        kezelésük korlátozását, kérheti az adatai hordozható formában történő kiadását, és tiltakozhat a
        jogos érdeken alapuló adatkezelés ellen. A kérést a
        <a href="mailto:{{ $email }}" class="font-medium text-blue-700 hover:underline">{{ $email }}</a>
        címen lehet előterjeszteni; arra legkésőbb egy hónapon belül válaszolunk.
    </p>

    <p>
        Ha a kérés olyan bizonylatra vonatkozik, amelyet egy Előfizető töltött fel, a Szolgáltató
        adatfeldolgozóként jár el: a kérést továbbítja az adatkezelő Előfizetőnek, és az ő utasítása
        szerint jár el.
    </p>

    <p>
        Panasszal a Nemzeti Adatvédelmi és Információszabadság Hatósághoz lehet fordulni
        (1055 Budapest, Falk Miksa utca 9–11.; postacím: 1363 Budapest, Pf. 9.;
        <a href="mailto:ugyfelszolgalat@naih.hu" class="font-medium text-blue-700 hover:underline">ugyfelszolgalat@naih.hu</a>),
        illetve bírósághoz.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">9. A tájékoztató módosítása</h2>

    <p>
        A Szolgáltató a jelen tájékoztatót módosíthatja, ha az adatkezelés módja megváltozik — például új
        közreműködő lép be, vagy más megőrzési idő lép életbe. A módosításról az Előfizetőket e-mailben
        tájékoztatja, a hatályos szöveg pedig mindig ezen az oldalon érhető el.
    </p>

</x-layouts.jogi>
