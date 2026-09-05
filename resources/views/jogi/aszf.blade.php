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
    $hatalyos = '2026. szeptember 5.';

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
            bizonylatokat tölthet fel a böngészőből, vagy elküldheti azokat a cégéhez rendelt, saját
            beküldési e-mail címre;
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
        Egy fiókhoz egy cég tartozik, egy darabkerettel és egy beküldési e-mail címmel. A céget létrehozó
        felhasználó a tulajdonos: ő hívhat meg további felhasználókat, ő módosíthatja a beállításokat és
        az előfizetést. A meghívott felhasználók a szerepkörük szerinti jogokat kapják.
    </p>

    <p>
        A belépési adatok megőrzése az Előfizető felelőssége; a fiókjában végzett műveletekért az
        Előfizető felel. A beküldési e-mail cím kitalálhatatlan tokent tartalmaz — aki ismeri, a cég
        nevében tud iratot beküldeni —, ezért azt csak arra jogosultakkal szabad megosztani.
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
        A beküldési postafiókba érkezett leveleket a rendszer a feldolgozás után legfeljebb hét, a
        besorolhatatlan leveleket legfeljebb tizennégy napig őrzi, azután törli.
    </p>

    <p>
        <strong>A bizonylatok jogszabályi megőrzése az Előfizető kötelezettsége.</strong> A számviteli és
        adójogi előírások szerinti megőrzési időt a Szolgáltatás nem teljesíti és nem helyettesíti; a
        Szolgáltató általi törlés az Előfizető megőrzési kötelezettségét nem érinti. Az Előfizetőnek
        ezért az eredeti bizonylatokat magának kell megőriznie.
    </p>

    <p>
        A szerződés megszűnése után a Szolgáltató az Előfizető adatait ésszerű időn belül törli. Az
        Előfizető a szerződés fennállása alatt bármikor exportálhatja az adatait; a megszűnést megelőző
        adatmentés az Előfizető feladata. Fiók és adatok soron kívüli törlése a
        <a href="mailto:{{ $email }}" class="font-medium text-blue-700 hover:underline">{{ $email }}</a>
        címen kérhető.
    </p>

    <h2 class="pt-4 text-base font-semibold text-slate-900">11. Adatkezelés</h2>

    <p>
        A feltöltött bizonylatok tekintetében az Előfizető az adatkezelő, a Szolgáltató pedig
        adatfeldolgozóként jár el: az adatokat kizárólag a Szolgáltatás nyújtásához, az Előfizető
        utasításai szerint kezeli, azokat harmadik félnek a Szolgáltatás nyújtásához igénybe vett
        közreműködőkön kívül nem adja át, és a szerződés megszűnésekor törli. A közreműködők köre, az
        adatkezelés célja, jogalapja és időtartama az
        <a href="{{ route('adatkezeles') }}" class="font-medium text-blue-700 hover:underline">Adatkezelési tájékoztatóban</a>
        található.
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
