{{--
    Az Általános Szerződési Feltételek.

    **A számok nincsenek beírva a szövegbe.** Ár, darabkeret, felhasználószám,
    próbaidő, megőrzési plafon, feltöltési méret: mind a configból és a
    kódból jön, ugyanabból a forrásból, amiből a rendszer is dolgozik. Egy
    kézzel bemásolt szám előbb-utóbb elcsúszik attól, amit a program valóban
    ad — az árlistán az még kellemetlen, egy szerződésben viszont az ígéret
    csúszik el a teljesítéstől.

    A hatályba lépés dátumát viszont **kézzel** kell átírni (`$hatalyos`),
    valahányszor a szöveg érdemben változik. Ez nem automatizálható: nem
    minden fájlmentés új szerződéses változat.

    Ez a szöveg abból íródott, amit a rendszer ténylegesen csinál, nem
    sablonból. Jogi felülvizsgálaton nem esett át.
--}}
@php
    $hatalyos = '2026. szeptember 7.';

    $csomagok = config('szamlafolyo.plans');
    $probaNap = (int) config('szamlafolyo.trial.days');
    $probaDb = (int) config('szamlafolyo.trial.documents');
    $probaFo = (int) config('szamlafolyo.trial.users');
    $plafon = (int) config('szamlafolyo.tulhasznalat.alap_plafon_ft');
    $maxMb = (int) round(config('szamlafolyo.upload.max_bytes') / 1024 / 1024);
    $megorzesMax = \App\Models\Company::MEGORZES_MAX_NAP;
    $email = config('szamlafolyo.kapcsolat_email');

    $szam = fn (int $ertek): string => number_format($ertek, 0, ',', ' ');
@endphp
<x-layouts.jogi cim="Általános Szerződési Feltételek">

    <p class="text-slate-500">Hatályos: {{ $hatalyos }}</p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">1. A Szolgáltató és a szerződés létrejötte</h2>

    <p>
        A SzámlaFolyó szolgáltatást <strong>Nyeste Krisztián egyéni vállalkozó</strong> (a továbbiakban:
        Szolgáltató) nyújtja. A Szolgáltató azonosító adatai és elérhetőségei az
        <a href="{{ route('impresszum') }}" class="font-medium text-blue-700 hover:underline">Impresszumban</a>
        találhatók.
    </p>

    <p>
        A szerződés a regisztrációval jön létre, a jelen ÁSZF és az
        <a href="{{ route('adatkezeles') }}" class="font-medium text-blue-700 hover:underline">Adatkezelési tájékoztató</a>
        elfogadásával. A regisztráló ezzel kijelenti, hogy a feltételeket megismerte, és magára nézve
        kötelezőnek fogadja el. A szerződés írásba foglalt szerződésnek nem minősül, azt a Szolgáltató
        nem iktatja; nyelve a magyar.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">2. A Szolgáltatás kizárólag vállalkozásoknak szól</h2>

    <p>
        A Szolgáltatást kizárólag a Polgári Törvénykönyv szerinti vállalkozások — gazdasági társaságok,
        egyéni vállalkozók és egyéb, önálló foglalkozásuk vagy gazdasági tevékenységük körében eljáró
        személyek — vehetik igénybe. A felhasználó a regisztrációval kijelenti, hogy vállalkozásként,
        gazdasági tevékenysége körében jár el.
    </p>

    <p>
        Ezt a rendszer is számon kéri: cég létrehozásához érvényes magyar adószám szükséges. A szerződés
        ennek megfelelően nem fogyasztói szerződés, a fogyasztókat megillető külön jogok (elállási jog,
        békéltető testületi eljárás) nem alkalmazandók.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">3. A Szolgáltatás tartalma</h2>

    <p>
        A SzámlaFolyó bejövő számlákat és egyéb bizonylatokat olvas ki gépi úton, és könyvelésre alkalmas
        formában ad tovább. A Szolgáltatás keretében az Előfizető:
    </p>

    <ul class="list-disc space-y-1 pl-5">
        <li>
            bizonylatokat tölthet fel a böngészőből;
        </li>
        <li>
            a kiolvasott adatokat egy ellenőrző képernyőn átnézheti és javíthatja — a rendszer megjelöli
            azokat a mezőket, amelyekben bizonytalan;
        </li>
        <li>
            a jóváhagyott tételeket XLSX, CSV vagy JSON formátumban exportálhatja, szűrve időszakra és
            vevő adószámára;
        </li>
        <li>az eredeti fájlokat az export mellé ZIP-ben letöltheti.</li>
    </ul>

    <p>
        Feltölthető fájltípusok: PDF, JPG, PNG, WEBP, valamint e-számla XML (UBL, Factur-X/ZUGFeRD CII).
        Egy fájl mérete legfeljebb {{ $maxMb }} MB.
    </p>

    <p>
        A kiolvasást a Szolgáltató által választott, külső mesterséges intelligencia modellszolgáltató
        végzi. Ennek adatvédelmi vonatkozásait az Adatkezelési tájékoztató tartalmazza. Az e-számla XML
        feldolgozása modellhívás nélkül történik.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">4. Amit a Szolgáltatás nem nyújt</h2>

    <p>
        <strong>A gépi kiolvasás eredménye tervezet, nem kész könyvelési adat.</strong> A Szolgáltató nem
        vállal szavatosságot a kiolvasott adatok helyességéért vagy teljességéért. A rendszer ezért kéri
        minden tétel emberi jóváhagyását: az adat helyességéért — és mindazért, ami abból a könyvelésben,
        a bevallásokban vagy máshol következik — az Előfizető felel.
    </p>

    <p>
        A Szolgáltatás nem minősül könyvelési, adótanácsadási vagy jogi szolgáltatásnak, és nem
        helyettesíti a könyvelő munkáját.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">5. A fiók, a cég és a felhasználók</h2>

    <p>
        Egy fiókhoz egy cég tartozik, egy darabkerettel. A céget létrehozó
        felhasználó a tulajdonos: ő hívhat meg további felhasználókat, ő módosíthatja a beállításokat és
        az előfizetést. A meghívott felhasználók a szerepkörük szerinti jogokat kapják.
    </p>

    <p>
        A belépési adatok megőrzése az Előfizető felelőssége; a fiókjában végzett műveletekért az
        Előfizető felel.
    </p>

    <p>
        Minden felhasználó bármikor törölheti a saját fiókját a Beállítások képernyőről. A cég adatai
        akkor szűnnek meg, ha a cégnek nem marad felhasználója; amíg más felhasználó dolgozik benne, a
        kilépő fiók törlése a cég adatait és az előfizetést nem érinti. A cég egyetlen tulajdonosa
        addig nem törölheti a fiókját, amíg a cégben más felhasználó van — előbb másik tulajdonost kell
        kijelölnie, vagy a többi felhasználót el kell távolítania.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">6. Próbaidő</h2>

    <p>
        A regisztrációt követően {{ $probaNap }} napos, bankkártya megadása nélküli próbaidő jár, amely
        alatt legfeljebb {{ $szam($probaDb) }} dokumentum dolgozható fel, és legfeljebb {{ $probaFo }}
        felhasználó vehető fel. A próbaidőt a kettő közül az zárja le, amelyik előbb elfogy. A próbaidő
        automatikusan megszűnik, fizetési kötelezettséget nem keletkeztet.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">7. Csomagok és díjak</h2>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[32rem] text-left">
            <thead>
                <tr class="border-b border-slate-300 text-slate-500">
                    <th class="py-2 pr-4 font-medium">Csomag</th>
                    <th class="py-2 pr-4 text-right font-medium">Havi díj</th>
                    <th class="py-2 pr-4 text-right font-medium">Dokumentum / hó</th>
                    <th class="py-2 pr-4 text-right font-medium">Felhasználó</th>
                    <th class="py-2 text-right font-medium">Extra dokumentum</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($csomagok as $cs)
                    <tr class="border-b border-slate-200">
                        <td class="py-2 pr-4 font-medium text-slate-900">{{ $cs['nev'] }}</td>
                        <td class="py-2 pr-4 text-right">{{ $szam((int) $cs['ar_havi']) }} Ft</td>
                        <td class="py-2 pr-4 text-right">{{ $szam((int) $cs['documents']) }}</td>
                        <td class="py-2 pr-4 text-right">{{ $cs['users'] === null ? 'korlátlan' : $cs['users'] }}</td>
                        <td class="py-2 text-right">{{ $szam((int) $cs['extra_ft']) }} Ft</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p>
        A Szolgáltató alanyi adómentes, ezért a feltüntetett díjak áfát nem tartalmaznak, és azok a
        fizetendő végösszegek.
    </p>

    <p>
        Az előfizetés <strong>havi</strong> díjfizetésű; éves konstrukció nincs. A darabkeret mindig az
        aktuális számlázási időszakra szól, és a következő időszakra <strong>nem gördül át</strong>.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">8. Darabkeret, kredit és túlhasználat</h2>

    <p>
        A keret felhasználását a rendszer oldalarányosan méri, mert egy sokoldalas köteg feldolgozása nem
        ugyanannyi munka, mint egy egyoldalas nyugta. A szabály:
        <strong>{{ \App\Support\Kredit::szabaly() }}</strong> Egy szokásos, egy–három oldalas számla vagy
        nyugta tehát egy dokumentumnak számít. Ha egy irat oldalszáma nem állapítható meg, egy
        dokumentumnak számít.
    </p>

    <p>
        A keret kimerülése után a feldolgozás <strong>alapértelmezés szerint megáll</strong>. Az Előfizető
        tulajdonosa külön engedélyezheti a kereten felüli feldolgozást; ilyenkor az e feletti
        dokumentumok a csomaghoz tartozó darabáron kerülnek kiszámlázásra. Az engedély nem nyitott végű:
        forintban meghatározott felső határ tartozik hozzá, amelynek kezdőértéke
        {{ $szam($plafon) }} Ft, és amelyet a tulajdonos módosíthat vagy kikapcsolhat. A plafon elérése
        után a feldolgozás megáll.
    </p>

    <p>
        A felhasznált keretet a rendszer a ténylegesen elvégzett kiolvasások alapján tartja nyilván. Egy
        dokumentum utólagos törlése a már elvégzett kiolvasást nem teszi meg nem történtté, és a keretet
        nem adja vissza.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">9. Fizetés, számlázás, felmondás</h2>

    <p>
        A díjfizetés bankkártyával, a Stripe fizetési szolgáltatón keresztül történik. A Szolgáltató
        bankkártyaadatot nem kezel és nem tárol. Az előfizetés a számlázási időszak végén automatikusan
        megújul.
    </p>

    <p>
        Az Előfizető az előfizetést bármikor felmondhatja a Beállítások képernyőről elérhető számlázási
        felületen. A felmondás a kifizetett időszak végén lép hatályba; a már kifizetett díj időarányos
        visszatérítésére nincs mód. A felmondást követően a Szolgáltatás a keret és a felhasználószám
        szempontjából csomag nélküli állapotba kerül: új dokumentum feldolgozására nincs lehetőség.
    </p>

    <p>
    <p>
        Az előfizetés a fiók törlésével is megszűnik, de ez a felmondástól eltérően
        <strong>azonnal</strong> hatályos: a fiók törlésével az adatok is törlődnek, így a kifizetett
        időszak hátralévő része nem használható fel, és nem téríthető vissza. Ha az Előfizető a
        kifizetett időszakot ki akarja használni, a Stripe ügyfélportálján mondja fel az előfizetést,
        és a fiókot csak az időszak végén törölje.
    </p>

    <p>
        Sikertelen fizetés esetén a Szolgáltató jogosult a feldolgozást felfüggeszteni. A Szolgáltató a
        szerződést harmincnapos határidővel, indokolás nélkül is felmondhatja; súlyos szerződésszegés —
        így különösen a Szolgáltatás jogellenes vagy visszaélésszerű használata — esetén azonnali
        hatállyal.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">10. Az iratok és a fájlok megőrzése</h2>

    <p>
        <strong>Az eredeti fájlok az export elkészültével törlődnek a szerverről.</strong> Ez alapesetben
        azonnal megtörténik; az Előfizető a Beállítások képernyőn türelmi időt állíthat be, amely
        legfeljebb {{ $megorzesMax }} nap lehet. Az export képernyő a törlést előre kimondja, és
        felkínálja az eredetik ZIP-ben történő letöltését. A kiolvasott és jóváhagyott adatok a törlés
        után is megmaradnak.
    </p>

    <p>
        <strong>A bizonylatok jogszabályi megőrzése az Előfizető kötelezettsége.</strong> A számviteli és
        adójogi előírások szerinti megőrzési időt a Szolgáltatás nem teljesíti és nem helyettesíti; a
        Szolgáltató általi törlés az Előfizető megőrzési kötelezettségét nem érinti. <strong>A
        Szolgáltatás „Archívum" képernyője ebben az értelemben nem archiválás:</strong> az az elkészült
        exportokat tartja nyilván, hogy azok visszakereshetők legyenek — munkafolyamati funkció, nem a
        jogszabály szerinti bizonylatmegőrzés, és azt nem is pótolja. Az Előfizetőnek
        ezért az eredeti bizonylatokat magának kell megőriznie.
    </p>

    <p>
        A szerződés megszűnése után a Szolgáltató az Előfizető adatait ésszerű időn belül törli. Az
        Előfizető a szerződés fennállása alatt bármikor exportálhatja az adatait; a megszűnést megelőző
        adatmentés az Előfizető feladata.
    </p>

    <p>
        <strong>A fiók törlése azonnali és végleges.</strong> Ha a törléssel a cégnek nem marad
        felhasználója, a Szolgáltató a törléssel egyidejűleg lemondja az előfizetést, és véglegesen
        törli a cég bizonylatait, a kiolvasott adatokat, az exportokat és a feltöltött fájlokat.
        A törlés nem vonható vissza, és a törölt adatokról a
        Szolgáltató nem tart fenn másolatot. A már kiállított számlák ez alól kivételt képeznek: azokat
        a Szolgáltató a számviteli előírások szerinti ideig megőrzi. A törlés az Előfizetőt terhelő
        jogszabályi megőrzési kötelezettséget nem érinti. Ha a törlés a felületről bármi okból nem
        megy, az a
        <a href="mailto:{{ $email }}" class="font-medium text-blue-700 hover:underline">{{ $email }}</a>
        címen is kérhető.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">11. Adatkezelés — adatfeldolgozási feltételek</h2>

    <p>
        A feltöltött bizonylatok tekintetében az Előfizető az adatkezelő, a Szolgáltató pedig
        adatfeldolgozóként jár el. <strong>Ez a pont egyben a felek közötti adatfeldolgozási
        szerződés</strong> a GDPR 28. cikk (3) bekezdése szerint; külön okirat aláírása nem szükséges.
        A fiók adataira (a regisztráló neve, e-mail címe, a cég adatai, az előfizetés) nézve viszont a
        Szolgáltató önálló adatkezelő — arról az
        <a href="{{ route('adatkezeles') }}" class="font-medium text-blue-700 hover:underline">Adatkezelési tájékoztató</a>
        szól.
    </p>

    <p>
        <strong>Az adatfeldolgozás tárgya és időtartama:</strong> a Szolgáltatás nyújtása, a jelen
        szerződés hatálya alatt. <strong>Jellege és célja:</strong> a feltöltött bizonylatok tárolása,
        gépi kiolvasása, az adatok ellenőrizhetővé tétele és exportálása. <strong>A kezelt adatok
        típusa:</strong> a bizonylatokon szereplő adatok — így a partner neve, címe, adószáma, a
        bizonylat számai és tételei —, amelyek személyes adatnak minősülnek, ha a partner egyéni
        vállalkozó vagy magánszemély. <strong>Az érintettek köre:</strong> az Előfizető partnerei és
        azok képviselői, valamint az Előfizető által felvett felhasználók.
    </p>

    <p>A Szolgáltató adatfeldolgozóként vállalja, hogy:</p>

    <ol class="list-[lower-alpha] space-y-1 pl-5">
        <li>
            a személyes adatokat kizárólag az Előfizető írásbeli utasítása alapján kezeli — ideértve a
            harmadik országba történő adattovábbítást is —, kivéve, ha a kezelést uniós vagy tagállami
            jog írja elő; a Szolgáltatás rendeltetésszerű használata (feltöltés, kiolvasás, export)
            ilyen utasításnak minősül;
        </li>
        <li>
            biztosítja, hogy az adatokhoz hozzáférő személyek titoktartási kötelezettséget vállaltak
            vagy jogszabályon alapuló titoktartási kötelezettség alatt állnak;
        </li>
        <li>
            megteszi a GDPR 32. cikke szerinti biztonsági intézkedéseket; ezek felsorolása az
            Adatkezelési tájékoztató „Adatbiztonság" pontjában található;
        </li>
        <li>
            további adatfeldolgozót az Előfizető <strong>általános felhatalmazása</strong> alapján vesz
            igénybe. A mindenkori al-adatfeldolgozók az Adatkezelési tájékoztatóban név szerint
            szerepelnek. Új al-adatfeldolgozó igénybevétele előtt a Szolgáltató az Előfizetőt
            <strong>legalább tizenöt nappal korábban</strong> e-mailben értesíti; az Előfizető ez ellen
            kifogást emelhet, és ha a felek nem jutnak megegyezésre, a szerződést a változás
            hatálybalépéséig felmondhatja. A további adatfeldolgozókra a Szolgáltató ugyanezeket a
            kötelezettségeket telepíti;
        </li>
        <li>
            az Előfizetőt a technikai lehetőségeihez mérten segíti az érintetti kérelmek (hozzáférés,
            helyesbítés, törlés, korlátozás, hordozhatóság, tiltakozás) teljesítésében; ha ilyen kérelem
            közvetlenül a Szolgáltatóhoz érkezik, azt továbbítja az Előfizetőnek, és önállóan nem jár el;
        </li>
        <li>
            segíti az Előfizetőt a GDPR 32–36. cikke szerinti kötelezettségei teljesítésében.
            <strong>Adatvédelmi incidens esetén a Szolgáltató indokolatlan késedelem nélkül, de
            legkésőbb az észleléstől számított negyvennyolc órán belül</strong> értesíti az Előfizetőt,
            és megad minden rendelkezésére álló információt;
        </li>
        <li>
            a szerződés megszűnésekor — az Előfizető választása szerint — az adatokat törli vagy
            visszaadja, és a meglévő másolatokat törli, kivéve, ha jogszabály a megőrzést előírja
            (ilyen a Szolgáltató által kiállított számla);
        </li>
        <li>
            az Előfizető rendelkezésére bocsát minden olyan információt, amely a jelen pont szerinti
            kötelezettségek igazolásához szükséges, és lehetővé teszi az Előfizető vagy az általa
            megbízott ellenőr által végzett auditot. Az ellenőrzést előzetesen egyeztetett időpontban,
            a Szolgáltatás működésének indokolatlan zavarása nélkül kell lefolytatni.
        </li>
    </ol>

    <p>
        <strong>A Szolgáltató az Előfizető bizonylatait nem használja fel mesterséges intelligencia
        modell tanítására</strong>, sem sajátéra, sem harmadik félére, és erre külön megállapodás
        hiányában nem is jogosult. A gépi kiolvasáshoz igénybe vett szolgáltató felé a Szolgáltató
        kiköti, hogy a bizonylat tartalma nem tárolható és tanításra nem használható; erről az
        Adatkezelési tájékoztató 3. pontja szól részletesen.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">12. Rendelkezésre állás és karbantartás</h2>

    <p>
        A Szolgáltató a Szolgáltatást az elvárható gondossággal, folyamatos rendelkezésre állásra
        törekedve üzemelteti, de meghatározott rendelkezésre állási szintet nem garantál. A Szolgáltató
        jogosult a Szolgáltatást karbantartás céljából szüneteltetni; a tervezett, a szokásosnál hosszabb
        szünetről lehetőség szerint előre tájékoztat.
    </p>

    <p>
        A Szolgáltató nem felel azért a kimaradásért, amely rajta kívül álló okból — így a
        tárhelyszolgáltató, a fizetési szolgáltató, a modellszolgáltató vagy az internetkapcsolat hibájából
        — következik be.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">13. Felelősség</h2>

    <p>
        A Szolgáltató felelőssége a szerződésszegéssel okozott károkért — a szándékosan okozott, továbbá
        az emberi életet, testi épséget vagy egészséget megkárosító szerződésszegés esetét kivéve —
        összesen legfeljebb a káresemény bekövetkeztét megelőző hat hónapban ténylegesen megfizetett
        szolgáltatási díj összegéig terjed.
    </p>

    <p>
        A Szolgáltató nem felel az elmaradt haszonért, az adatvesztésből eredő közvetett kárért, továbbá a
        gépi kiolvasás hibájából eredő károkért, ha az Előfizető a tételt a jóváhagyás előtt nem
        ellenőrizte.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">14. Szellemi tulajdon</h2>

    <p>
        A SzámlaFolyó név, a logó, a weboldal tartalma és a Szolgáltatást működtető szoftver a Szolgáltató
        szellemi tulajdona. Az Előfizető a Szolgáltatás használatára nem kizárólagos, át nem ruházható
        jogot kap a szerződés időtartamára. A szoftver visszafejtése, másolása vagy továbbértékesítése
        nem megengedett. A feltöltött bizonylatok és a belőlük kiolvasott adatok az Előfizető tulajdonában
        maradnak.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">15. Az ÁSZF módosítása</h2>

    <p>
        A Szolgáltató jogosult a jelen ÁSZF-et és a díjakat egyoldalúan módosítani. A módosításról az
        Előfizetőt a hatálybalépést megelőzően legalább tizenöt nappal e-mailben tájékoztatja. Ha az
        Előfizető a módosítást nem fogadja el, a hatálybalépésig felmondhatja az előfizetést; a
        Szolgáltatás további használata a módosítás elfogadásának minősül. A díjemelés a már kifizetett
        számlázási időszakot nem érinti.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">16. Alkalmazandó jog és jogviták</h2>

    <p>
        A jelen szerződésre a magyar jog irányadó. A felek a vitáikat elsősorban egyeztetéssel rendezik;
        ennek eredménytelensége esetén a magyar bíróságok járnak el az általános szabályok szerint.
        A jelen ÁSZF-ben nem szabályozott kérdésekben a Polgári Törvénykönyv és az elektronikus
        kereskedelmi szolgáltatásokról szóló 2001. évi CVIII. törvény rendelkezései az irányadók.
    </p>

    <p class="pt-2 text-slate-500">
        Kérdés esetén:
        <a href="mailto:{{ $email }}" class="font-medium text-blue-700 hover:underline">{{ $email }}</a>
    </p>

</x-layouts.jogi>
