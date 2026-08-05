# Számlafolyó

Iktató- és ügykezelő webalkalmazás magyar kis- és középvállalkozásoknak.
A beérkező iratokat AI olvassa be és iktatja — az ember csak ellenőriz és jóváhagy.

**Stack:** Next.js (App Router, TypeScript) · Supabase (Postgres, Auth, Storage, RLS) · OpenRouter API (AI-kinyerés) · Vercel

A termékdefiníció a `docs/` mappában, az 1. mérföldkő terve: `docs/milestone-1-terv.md`.

## Felépítés

- `supabase/migrations/` — a teljes séma és minden RLS policy, migrációnként. Séma-változtatás csak itt.
- `src/lib/extraction/` — AI-kinyerés: verziózott prompt, zod-séma, determinisztikus validátorok (adószám-ellenőrzőszámjegy, nettó+ÁFA=bruttó, dátumok).
- `src/app/api/upload` — feltöltés, szerveroldali sha256, duplikátum-jelzés.
- `src/lib/jobs/claim.ts` — claim-alapú, idempotens kinyerés-indítás; a feltöltés `after()`-je és a cron hívja, processen belül (nincs HTTP-önhívás).
- `src/app/api/cron/sweep` — percenkénti söprés: elakadt/hibás feldolgozások újraindítása (max. 3 kísérlet).
- `src/lib/upload/store.ts` — egy helyen dől el, hogy egy beérkező fájlból irat lesz-e; a feltöltés és az e-mailes beérkeztetés is ezt hívja, hogy a duplikátumszabályok ne csússzanak szét.
- `src/app/api/email/inbound` — Resend `email.received` webhook: aláírás-ellenőrzés, cég feloldása a cím tokenjéből, mellékletek iratként. Terv: `docs/email-beerkeztetes-terv.md`.
- `src/app/(app)/ellenorzes/[documentId]` — osztott ellenőrző képernyő; Enter = iktatás és ugrás a következőre.
- Az iktatószám-kiosztás egyetlen Postgres-tranzakció (`iktat_document` RPC): `SELECT ... FOR UPDATE` az `iktatokonyv.next_foszam` soron — hézagmentes, ütközésmentes főszám garantált. Meglévő ügy alá iktatva (`p_ugy_id`) ugyanez a fegyelem az ügy során: a következő **alszám** kiosztása is sorzár alatt történik.
- `src/lib/fizetes/schedule.ts` — fizetési naptár: mi van nyitva és mikor jár le.
  Csak **bejövő**, iktatott, nem érvénytelenített irat számít tartozásnak; a nyugta
  nem (az a pénztárnál már kifizetett), a díjbekérő viszont igen. Ha egy ügyön belül
  a díjbekérő és a rá kiállított számla összege megegyezik, **csak a számla számít** —
  különben duplán jelenne meg ugyanaz a tartozás. Az összegek pénznemenként külön
  adódnak össze, soha nem keverve.
- `src/lib/ugy/status.ts` — az ügy állapotgépe. Ugyanaz a nyolc átmenet él a
  TypeScriptben és az `app.protect_ugy()` triggerben, és teszt köti össze a
  kettőt: a képernyő nem kínálhat olyan gombot, amit az adatbázis elutasít.
  `folyamatban` soha nem ugorhat egyenesen az irattárba — előbb lezárás jön,
  ez a tényleges ügyviteli sorrend. Az irattárazott ügy metaadata be van
  fagyasztva, amíg ki nem veszik onnan.
- `src/lib/ugy/order.ts` — **a határidő csak addig határidő, amíg az ügy fut.**
  Egy májusban irattárazott ügy hónapokig azt írta ki, hogy „84 napja lejárt",
  és mivel az övé volt a legrégebbi dátum, a lista tetejére került — a valóban
  nyitott munka fölé. A lezárt és irattárazott ügy ezért minden futó alá süllyed,
  a dátuma megmarad, de nem visszaszámlálás és nem piros. Ami viszont **nem**
  szűnik meg egy ügy lezárásával, az a kifizetetlen számla: az ügy adatlapja
  ezt külön kiírja, és a fizetési naptár változatlanul hozza.
- `src/lib/errors.ts` — a hibaüzenetek közös rétege. Amit egy funkció előre lát
  („eltérő törzsszám", „az ügy irattárazott"), azt ott fordítjuk le, ahol
  keletkezik; ez azokat kezeli, amiket egyik funkció sem lát előre — és amik a
  leggyakrabban megtörténnek: a kérés meg sem érkezik. A megszakadt kapcsolat
  eddig `TypeError: fetch failed` néven jelent meg a mondat közepén. Most azt
  mondja meg, ami a felhasználót érdekli: **megtörtént-e az írás**. Hálózati
  hibánál nem — a kérés el sem indult.
- `src/lib/irattar/terv.ts` — **meddig kell megőrizni.** A megőrzési idő az ügy
  *lezárásának* évétől számít: a Számviteli törvény a bizonylat üzleti évétől
  számol, egy éven belül nyitott és lezárt ügynél ez ugyanaz, ahol pedig
  eltérnek, ott a lezárás a későbbi — így a számolás soha nem selejtez korán.
  A modul legfontosabb sora, hogy a **null őrzési idő nem hiányzó adat, hanem
  a „nem selejtezhető"**: a legerősebb érték, amit a mező felvehet.
- `src/lib/nav/` — a **NAV Online Számla** kapcsolat. Az egész réteg csak
  **lekérdez**: nincs benne `manageInvoice`, és teszt őrzi, hogy ne is kerüljön
  bele. Az adatszolgáltatás azé a programé, amelyik a számlát kiállította; egy
  második bejelentés ugyanarról a számláról a cég adószámán valótlan
  adatszolgáltatás. A `signature.ts` az egyetlen hely, ahol a hitelesítés
  szabályai le vannak írva — a 3.0 **SHA3-512**-vel írja alá a kérést (a 2.0
  még SHA-512-vel), és ez a tévesztés ugyanúgy `INVALID_SECURITY_USER`-ként
  jelenik meg, mint a rossz jelszó, ezért teszt köti le a vektort. Az `xml.ts`
  szándékosan szűk értelmező: **elutasít mindent, amit nem ért**, mert egy
  csendben eldobott `<invoiceDigest>` pontosan úgy néz ki, mint egy hiányzó
  számla. A `secret.ts` AES-256-GCM-mel titkosít, a `company_id`-t hitelesített
  adatként a rejtjelbe kötve: másik cég sorába átmásolva nem fejthető vissza.
  A `reconcile.ts` a lényeg, és tiszta függvény — hálózat és óra nélkül
  eldönthető, mit állít.
- `src/lib/audit/` — az **audit napló** olvasó- és megjelenítő rétege. A
  `labels.ts` fordítja magyarra, amit a triggerek nyersen rögzítenek (esemény,
  oszlopnév, érték), a `query.ts` pedig az egyetlen hely, ahonnan a naplót
  lekérdezzük — így a Napló képernyő és az ügy „Előzmények" doboza nem
  gondolhat mást a rendezésről. Egy uuid-mezőből sosem uuid lesz a képernyőn,
  hanem az, ami vele történt („hozzárendelve", „eltávolítva").
- `src/lib/partner/` — a partnertörzs. Az `identity.ts` két dolgot tükröz az
  adatbázisból: a névnormalizálást (`app.normalize_company_name()`) és a
  **törzsszám**-összehasonlítást (`app.tax_number_core()`). Az adószám ÁFA-kódja
  és megyekódja változhat egy cég élete során, a törzsszám nem — ezért az dönti
  el, hogy ugyanarról az adóalanyról van-e szó. Az adószám ellenőrző számjegyét
  ellenőrzi is (9-7-3-1-9-7-3 súlyozás, a register három valódi számláján
  igazolva). A `bank-account.ts` az IBAN-t a mod-97 szabály szerint **elutasítja**,
  ha rossz, a magyar GIRO-szám blokk-ellenőrzőjét viszont csak **figyelmezteti**:
  jó elgépelés-jelző, de egy téves validátor soha ne álljon egy valódi utalás
  útjába. A `duplicates.ts` javaslatot ad, nem cselekszik — és a cégformát
  **összehasonlítja**, nem levágja, mert a „Nethely Kft." és a „Nethely Bt."
  két cég.
- `src/lib/export/` — könyvelői export. A `csv.ts` pontosvesszős, BOM-os,
  magyar tizedesvesszős CSV-t ír, mert a magyar Excel ezt olvassa számként; a
  szöveges cellákat formula-injekció ellen védi (`=`, `+`, `-`, `@` elé
  aposztróf), a számoszlopokat viszont nem, különben a sztornó mínusza
  szövegbe fordulna. Az **összesítés csak a számviteli bizonylatokra** megy
  (számla, előlegszámla, helyesbítő, sztornó, nyugta): a díjbekérő és a rá
  kiállított számla együtt kétszer vinné be ugyanazt a költséget. A
  „Könyvelendő" oszlop és az összesítés **ugyanarra a kérdésre felel** — az
  érvénytelenített számla is „nem", különben az Excelben rászűrve többet
  adna össze a könyvelő, mint amit az app kiír. Teszt őrzi. A `zip.ts`
  saját, tömörítés nélküli (STORE) ZIP-író — a PDF már tömörített, ezért a
  deflate csak CPU-t égetne, így pedig nincs új függőség.
- `src/lib/iktatas/ugy-suggest.ts` — determinisztikus ügy-javaslat (nem modellhívás): azonos partner **és** azonos összeg, és a javaslat mindig megmondja, miért ajánlja. Semmi nincs előre kiválasztva.
- `app.resolve_partner()` — partner-feloldás adószám, majd normalizált név alapján. A normalizálás kisbetűsít, ékezetet és írásjelet távolít el, de a **cégformát nem** vágja le: a `Kft.` és a `Bt.` két külön jogi személy. Adószám nélküli élő partnereknél részleges unique index garantálja, hogy egy névhez egy sor tartozzon.
- `src/app/globals.css` — a dizájnrendszer. A `@theme` blokkban a vászonszín,
  a betűcsalád és a kártyaárnyékok; a `@layer components` blokkban az összes
  ismétlődő elem egyetlen definíciója: `card`, `btn`, `control`, `badge`,
  `chip`, `tbl`/`th`/`td`/`trow`, `alert`, `empty`, `nav-item`. Azért
  komponensrétegben vannak, mert így az elemre írt utility **felülírja** őket —
  a `.control` teljes szélességű, de egy `w-72` mellette továbbra is szűkít.
  Új képernyő ne írjon saját `rounded-lg border border-… bg-white` kombinációt:
  kilenc fájlban kilenc kicsit másmilyen kártya volt, ezt szünteti meg.
- `src/components/app/` — a keret: `Nav.tsx` (oldalsáv ikonokkal, szekciókkal
  és a Beérkezőn a **rád váró** iratok számával — a feldolgozás alatt álló irat
  még nem feladat), `AuthShell.tsx` (bejelentkezés, regisztráció, cégnyitás
  közös kártyája).
- `src/components/irattar/` — az irattári terv szerkesztője. A „nem
  selejtezhető" külön jelölőnégyzet, nem az üresen hagyott évszám-mező: döntés,
  nem a döntés hiánya.
- `src/components/audit/` — a naplóbejegyzés egyetlen alakja. Az `AuditList`
  a Napló képernyőn és az ügy/partner adatlap „Előzmények" dobozában is
  ugyanaz, mert egy esemény kétféle megjelenítése előbb-utóbb kétféle igazság.
- `src/components/ui/` — `page.tsx` a `PageHeader` és az `EmptyState`,
  `icons.tsx` a néhány vonalas ikon. Az ikonok itt vannak megrajzolva, nem
  csomagból: tizenegy path miatt nem kell külön függőség és bundle.

## Fejlesztés

```bash
npm install
cp .env.example .env.local   # töltsd ki a kulcsokat
npm run dev
```

Szükséges környezeti változók: lásd `.env.example`. A `SUPABASE_SERVICE_ROLE_KEY`,
az `OPENROUTER_API_KEY` és a `CRON_SECRET` csak szerveroldalon él.
A kinyerő modell az `EXTRACTION_MODEL`-lel váltható (OpenRouter slug; PDF/kép
bemenet és tool-hívás támogatása kell).

## Tesztek

```bash
cp .env.test.example .env.test   # anon kulcs
npm test
```

Hálózat és adatbázis nélkül fut, tehát CI-ban minden pusholásnál:

- `tests/amount.test.ts` — a magyar összegformátum oda-vissza alakítása
  (`1 612 900,25`).
- `tests/iktatoszam-order.test.ts` — az iktatószám numerikus sorrendje
  (`IKT/10` a `IKT/9` **után** jön, nem előtte).
- `tests/doc-kind.test.ts` — az irattípus-szótár és a `public.doc_kind` enum
  nem csúszhat szét: a teszt a **migrációs fájlokból építi újra** az enumot, és
  ahhoz hasonlítja a TypeScript-listát.
- `tests/ugy-status.test.ts` — az ügy-állapotgép és a migráció nem csúszhat
  szét: a teszt a `v_allowed` tömbből építi újra az engedélyezett átmeneteket,
  és ahhoz hasonlítja a TypeScript-listát. A lista rendezését is fedi
  (közelgő határidő elöl, határidő nélküli ügyek hátul).
- `tests/partner-identity.test.ts` — a névnormalizálás a **migrációból
  kiolvasott** `translate()` betűpárokhoz van kötve, hogy a képernyő és az
  unique index ne állapítson meg mást két sorról; az adószám ellenőrző
  számjegye három valódi, a registerben iktatott számla adószámán; az IBAN
  négy publikált mintán, plusz egy elrontott ellenőrző számon; és hogy eltérő
  törzsszámú cégeket a duplikátumkereső **soha** nem ajánl összevonásra.
- `tests/irattari-terv.test.ts` — a megőrzési idő számolása (a 2026-ban lezárt,
  8 éves tétel 2034. december 31-ig őrzendő, és 2035-től selejtezhető), a
  szilveszter éjjeli lezárás **budapesti** éve, és hogy a null őrzési idő nem
  selejtezhetőt jelent, nem azonnal selejtezhetőt. Az alapértelmezett tervet
  **a migrációból olvassa vissza**: a számviteli bizonylatok 8 éve és a
  munkaügyi iratok „nem selejtezhető" jelölése nem csúszhat el csendben, és
  minden tételnek meg kell mondania, mire alapozza a határidőt.
- `tests/nav-protocol.test.ts` — a NAV-hitelesítés rögzített vektorokon: a
  jelszó SHA-512, a kérés aláírása **SHA3-512** (és a teszt külön kimondja,
  hogy nem SHA-512), a fejléc és az aláírás ugyanarra a másodpercre mutat, a
  kérés elemei az XSD sorrendjében mennek, és a jelszó nyersen soha nem kerül
  a kérésbe. Egy **valódi alakú** `queryInvoiceDigest` válaszon fut az olvasás
  (`ns2:` előtaggal, entitásokkal); az értelmező hibára fut DOCTYPE-on,
  ismeretlen entitáson és nem illeszkedő záró elemen. A 35 napos ablakokra
  bontás hézag- és átfedésmentes. És egy teszt végigolvassa az egész
  `src/lib/nav/` mappát, hogy **ne hivatkozzon beküldő műveletre**.
- `tests/nav-reconcile.test.ts` — az egyeztetés: mit hiányol és mit nem. A
  díjbekérőt, a nyugtát, a külföldi szállítót és az érvénytelenített iratot
  nevesítve hagyja ki; a tegnap kelt számlát nem hiányolja a NAV-nál; az
  áfacsoport tagjának adószámán is párosít; két szállító azonos számlaszáma
  nem keveredik össze; ugyanannak a számlának két bejelentése egy tétel; és
  ugyanarra a két listára mindig ugyanazt a párosítást adja.
- `tests/audit-labels.test.ts` — a napló szótára és a migráció nem csúszhat
  szét: a teszt **a migrációs fájlból olvassa ki** az összes eseménynevet, amit
  a triggerek írnak, és megköveteli, hogy mindegyiknek legyen magyar
  megfelelője — különben a képernyőn nyers `document.valami` kulcs jelenne meg.
  Fedi az uuid-mezők leírását, a hosszú szöveg levágását, és hogy az időszak-szűrő
  **budapesti** napkezdettel dolgozik, nyári és téli időszámításban is.
- `tests/export-csv.test.ts` — hónaphatárok (szökőév is), a magyar
  számformátum, a formula-injekció elleni védelem, és hogy a díjbekérő nem
  duplázza meg a könyvelendő összeget.
- `tests/export-zip.test.ts` — az archívumot a **rendszer `unzip`-je** nyitja
  meg és ellenőrzi (`unzip -t`), nem a saját olvasónk; az ékezetes fájlnév és
  a bájtra pontos tartalom is visszaolvasva.
- `tests/email-inbound.test.ts` — webhook-aláírás (hamisítás, visszajátszás,
  hiányzó fejléc), címzett-feloldás és payload-olvasás. A payload-teszt egy
  **valódi Resend-kézbesítés** szó szerinti másolatán fut, nem kitalált alakon.

A többi teszt a Supabase projekt **publikus API-ján** fut (anon kulcs +
jelszavas bejelentkezés), tehát pontosan azt az utat gyakorolja, amit az
alkalmazás:

- `tests/iktatas-concurrency.test.ts` — 50 párhuzamos iktatás: a főszámok halmaza
  pontosan összefüggő tartomány, se hézag, se ütközés; rollback nem hagy lyukat;
  dupla iktatás tiltva. Ugyanez az **alszámra** is: 20 párhuzamos iktatás egyetlen
  ügy alá pontosan a 2..21 alszámokat osztja ki, mind ugyanazzal a főszámmal;
  lezárt ügy alá nem lehet iktatni.
- `tests/retention.test.ts` — iktatott iratot nem lehet soft-delete-elni, átszámozni,
  se visszaléptetni; egyedül `ervenytelenitve`-be léphet, az iktatószámát megtartva.
  Iktatás előtt viszont elvethető és visszaállítható, szám elégetése nélkül.
  Ugyanez az **ügyre**: a főszám és az év nem írható át, lezáratlan ügyet nem
  lehet irattárazni, az irattárazott ügy be van fagyasztva, és az irattárból
  visszavett ügy megtartja az eredeti lezárási dátumát.
- `tests/partner-dedup.test.ts` — adószám nélküli szállító nem kap minden
  iktatásnál új partner-sort (kis/nagybetű, ékezet, írásjel egyezik); a `Kft.`
  és a `Bt.` viszont **nem** olvad össze; a később megjelenő adószám a meglévő
  partnerre íródik, nem hoz létre másodikat. Az **összevonás** is itt fut:
  átviszi az iratokat és az ügyeket, nyugdíjazza a beolvasztott sort, és a
  `unmerge_partner()` pontosan azokat a sorokat viszi vissza, amelyeket
  elmozdított; eltérő adószámú cégeket az adatbázis elutasít; összevont
  partner nem szerkeszthető, és partner nem költöztethető másik céghez.
- `tests/audit-trail.test.ts` — az iktatás bejegyzést ír, a bejegyzésben ott a
  személy és az iktatószám; az ügy megnyitása **megelőzi** az iratét ugyanabban
  a tranzakcióban; egy tag a saját cége bejegyzését sem módosítani, sem törölni,
  sem hamisítani nem tudja; másik cég naplója láthatatlan; és az érvénytelenítés
  indoka bekerül a naplóba.
- `tests/rls-isolation.test.ts` — másik cég usere semmilyen úton nem látja, nem
  módosítja és nem iktatja az első cég iratait; a `p_ugy_id`-vel sem tudja a saját
  iratát idegen ügy alá fűzni; anon semmit nem lát; fizikai törlés senkinek.

A két teszt-user (`teszt.a@szamlafolyo-test.hu`, `teszt.b@szamlafolyo-test.hu`)
a projektben seedelve van megerősített e-maillel. A jelszavuk **nincs a
repóban** — állítsd be a `TEST_USER_PASSWORD`-öt a `.env.test`-ben.

Ha nincs `.env.test`, ezek a suite-ok **kihagyódnak** (a vitest kiírja, hány
tesztet hagyott ki — nem hamis zöld), az offline tesztek viszont futnak.
Ez szándékos: a concurrency-suite futásonként 50 valódi iratot iktat abba a
projektbe, amire mutat, ezért nem szabad minden pusholásnál a produkciós
adatbázisra ereszteni. Amíg nincs külön staging Supabase projekt, ezt a kettőt
kézzel, tudatosan kell futtatni.

## CI

A `main` közvetlenül élesbe deployol, ezért a `.github/workflows/ci.yml` az
egyetlen kapu commit és éles között: típusellenőrzés, lint, a hálózat nélküli
tesztek és build minden pusholásnál és pull requestnél.

## Üzemeltetési megjegyzések

- A worker és a cron a Vercelen fut; a percenkénti cron Pro csomagot igényel.
- Az iratok privát Storage bucketben (`iratok`), útvonal: `{company_id}/{document_id}/{fájlnév}`.
- **Iktatás előtt** az irat elvethető (`elvetve` + `deleted_at`): a Beérkezőből eltűnik,
  de megmarad és visszaállítható. Iktatószámot nem kapott, tehát nem hagy hézagot.
- **Iktatás után** az irat fizikailag soha nem törölhető, és soft-delete sem érheti —
  egyedül az `ervenytelenitve` állapotba léphet, az iktatószámát megtartva
  (`ervenytelenit_document` RPC). Az érvénytelenítéshez **kötelező indoklás** kell,
  és csak `owner` vagy `admin` végezheti. Az iktatószám nem kerül újra kiosztásra,
  az ügyet pedig nem zárja le — az emberi döntés marad. Maga az érvénytelenítés
  ténye, indoka és időpontja utólag nem módosítható és nem vonható vissza.
  Ezt az `app.protect_iktatott_document()` trigger tartja be, nem a jó szándék:
  a `document_update` policy minden mezőt engedne, ezért a szabály a triggerben él,
  ahol a service role és a `SECURITY DEFINER` függvények sem kerülhetik meg.
- **Irattári terv** (`/irattari-terv`): melyik ügytípust meddig kell megőrizni.
  A tétel az **ügyhöz** tartozik, nem az irathoz — az ügy megy az irattárba, és
  a benne iktatott iratok osztoznak a sorsán; egy díjbekérőnek és a rá kiállított
  számlának nem lehet külön megőrzési ideje. A rendszer minden cégnek beveti
  ugyanazt a kiindulási tervet a hivatkozott jogszabállyal együtt, de ez
  **kiindulás, nem tanács**: minden sor szerkeszthető, és a megadott idők
  törvényi minimumok. A tervet csak `owner` vagy `admin` írhatja — a megőrzési
  idő rövidítése végső soron döntés arról, hogy iratokat meg lehet semmisíteni.
  A tételszám nem módosítható: az kerül rá az ügyre irattári jelként, és ha
  elmozdulhatna, egy régi ügy jele már nem arra a tételre mutatna, ami alatt
  iktatták.
- **Selejtezés: a rendszer soha nem töröl.** A `/irattari-terv` kilistázza,
  melyik ügy megőrzési ideje járt le, és ott megáll. A döntést és a
  megsemmisítést emberre hagyja, az iktatott irat fizikai törlése pedig az
  adatbázisban is tiltott marad. Ez nem óvatosság: egy magától selejtező
  ütemterv volna az egyetlen funkció, ami képes elveszíteni azt, amit ez az
  egész kódbázis őrizni hivatott.
- **NAV Online Számla** (`/nav`): az egyetlen lista, ami nem tőlünk függ.
  Minden más ellenőrzés a registert önmagával veti össze — egy meg nem érkezett
  irat viszont belülről pontosan úgy néz ki, mint egy soha el nem küldött. A
  NAV-nál viszont ott van, mert a szállító oda bejelentette. Az app **csak
  lekérdez**: adatszolgáltatást nem küld be, mert az annak a programnak a
  kötelezettsége, amelyik a számlát kiállította, és egy második bejelentés
  ugyanarról a számláról valótlan adatszolgáltatás a cég adószámán. Kapcsolóval
  sem lehet bekapcsolni; nincs is ilyen kód.
- A technikai felhasználóhoz elég a **„Számlák lekérdezése"** jogosultság, és
  csak a bejelentkezési név, a jelszó és az **aláíró kulcs** kell — a
  cserekulcs kizárólag a beküldéshez tartozik, ezért nem is kérjük be. A
  jelszó és az aláíró kulcs `NAV_SECRET_KEY`-jel titkosítva kerül a
  `nav_credential` sorba, a `company_id`-t hitelesített adatként a rejtjelbe
  kötve: egy adatbázis-dump önmagában nem tartalmazza a jelszót, és egy másik
  cég sorába átmásolt titok nem fejthető vissza. A képernyő soha nem írja
  vissza őket; üresen hagyva a korábbi érték marad.
- A **„Kapcsolat tesztelése”** egy lap bejövő digestet kér az elmúlt hétre —
  ugyanazt a műveletet, amit az egyeztetés is használ. A kézenfekvőbb
  `queryTaxpayer` (ami a cég saját nevét adná vissza) szándékosan nincs sehol:
  a NAV a **„Számlák kezelése”** joghoz köti, tehát egy helyesen beállított,
  csak lekérdező technikai felhasználó `FORBIDDEN`-t kapna tőle, ami rossz
  jelszónak látszik. A `nav-protocol` teszt őrzi, hogy ne kerüljön vissza.
  A „Saját számlák lekérdezése” jog is kevés: az csak a technikai felhasználó
  saját beküldéseit adja vissza, a bejövő oldalt nem.
- A NAV-tól kapott sorok (`nav_invoice`) **nem törölhetők és nem írhatók át**:
  nincs DELETE policy, a guard trigger pedig a számlaszámot is befagyasztja.
  Egy eltérés, amit el lehet tüntetni, nem kontroll. Az újraszinkron frissíti,
  amit a NAV mond, de egy bejelentett számlából nem csinál másikat.
- Amit az egyeztetés **nem** hiányol, azt nevesítve hagyja ki: a díjbekérő és a
  nyugta nem számla, a külföldi szállító számláját senki nem jelenti be a
  NAV-nak, az érvénytelenített irat pedig nem kérdés. A tegnap kelt számla sem
  hiányzik: a kiállítónak a következő munkanap végéig van ideje. Egy hamis
  riasztás megtanítja az embert átlapozni a listát, és onnantól a valódi is
  elvész benne.
- **Audit napló** (`/naplo`): ki, mit, mikor. A bejegyzéseket **triggerek**
  írják, nem az alkalmazás — a szerver-action, a kinyerő worker, az e-mailes
  beérkeztetés és egy kézi `psql` is ugyanazokat a táblákat írja, és az a
  napló, ami csak a beépített útvonalakat fedi, pont arról hallgat, amire
  kíváncsiak lennénk. A tábla **append-only**: a klienseknek nincs INSERT
  joguk (hamisítani nem lehet), egy `BEFORE UPDATE OR DELETE` trigger pedig
  minden szerepkörnek nemet mond, a service role-nak is. A cég minden tagja
  olvashatja — minden bejegyzés olyan sorról szól, amit amúgy is megnyithat,
  szűkíteni egy policy átírása. Az idő `clock_timestamp()`, nem `now()`:
  egy tranzakción belül (iktatás = ügy + iktatószám + irat) különben minden
  bejegyzés azonos másodpercet kapna, és a napló nem tudná megmondani, mi
  történt előbb. A napló a bekapcsolás napjától rögzít; visszamenőleg nincs
  benne semmi, és nem is találunk ki bele.
- A napló egyetlen eseménye, amit nem trigger lát: a **könyvelői export
  letöltése** (`log_export` RPC). Az adatbázisban semmi nem változik, amikor egy
  hónapnyi irat elhagyja a rendszert — épp ezért érdemes rögzíteni.
- A könyvelői export (`/export`) csak **iktatott** iratot ad ki; az
  érvénytelenítettek is benne vannak, jelölve, de az összegen nem mozdítanak.
  A ZIP-be az eredeti fájlok kerülnek, iktatószámmal kezdődő néven, és benne
  utazik ugyanaz a CSV is — az archívum önmagában értelmezhető. Az egész
  archívum memóriában áll össze, ezért 100 MB-nál és 2000 iratnál a route
  inkább elutasítja a kérést, mint hogy a függvény kifogyjon a memóriából.
- A **partnertörzs** (`/partnerek`) szerkeszthető, ezért az `app.protect_partner()`
  trigger őrzi: a partner nem költöztethető másik céghez (a `partner_update`
  policy engedné, és egy két céghez tartozó user átvihetné a sort úgy, hogy a
  régi cég iratai továbbra is rá mutatnak). Az összevonás egyetlen tranzakció
  (`merge_partner` RPC): átviszi az iratokat és az ügyeket, nyugdíjazza a
  beolvasztott sort, és feljegyzi, **pontosan mely sorokat mozdította el** —
  ezért a `unmerge_partner` vissza tudja adni őket, és csak őket. Két eltérő
  **törzsszám** összevonását az adatbázis elutasítja: az két adóalany. Az
  összevonás `owner`/`admin` jog, mint az érvénytelenítés. Az összevonás
  ténye sosem törlődik, csak „visszavonva" jelet kap.
- Az összevonás az **irattárazott ügy** `partner_id`-jét is átírja — ez az egyetlen
  kivétel az irattárazott ügy befagyasztása alól, mert egy nyugdíjazott
  duplikátumra mutató archív ügy pont az a rendetlenség, amit az összevonás
  megszüntet. Minden más mező marad fagyva.
- A gépi kinyerés értékei (`extraction.parsed_fields`) soha nem íródnak felül;
  a kézi javítás külön sor a `field_correction` táblában.
