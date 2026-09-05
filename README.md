# SzámlaFolyó

Bizonylat be, adat ki. A felhasználó feltölti vagy e-mailben beküldi a
számláit, az AI kiolvassa a típusukat és a mezőiket, az ember egy képernyőn
ellenőrzi — ahol a bizonytalan mezők színnel ki vannak emelve —, majd az egész
xlsx, csv vagy json formátumban a könyvelőhöz megy. Ami kiment, az az
archívumba kerül, ahonnan visszahívható vagy törölhető.

**Stack:** Laravel 12 · Livewire 3 · Tailwind 4 · PostgreSQL · OpenRouter · Stripe
**Hosting:** nethely.hu osztott tárhely (Pro) — app, adatbázis, fájlok és levelezés egy helyen.

## A legfontosabb, amit tudni kell róla

**Nincs háttér-worker.** Osztott tárhelyen nem futhat hosszú életű folyamat,
ezért a feldolgozási sort két dolog hajtja: a böngésző (amíg valaki nézi a
Beérkezőt, `wire:poll` néhány másodpercenként egy dokumentumot kiolvas) és a
cron (az e-mailben érkezett iratokért és az elakadt futásokért). Mindkettő
ugyanazt a claimet használja — egy feltételes `UPDATE ... WHERE status =
'feltoltve'` —, ezért nem tudják ugyanazt az iratot kétszer feldolgozni.
Lásd `app/Services/Extraction/Sorkezelo.php`.

**A keret abból számol, amit a felhasználó nem tud eltüntetni.** A felhasznált
darabszám a `document_extractions` hibátlan sorait kérdezi, nem a
`documents` táblát: azt a Beérkezőből, az Archívumból vagy egy egész export
törlésével bárki elviheti, és akkor a takarítás visszaadná a keretet. A
modellhívásért viszont már fizettünk. Ezért éli túl a kiolvasás sora a
dokumentumot (`document_id` nullázódik, a sor marad) — ez egyben az az
audit-nyom is, amiből utólag kiderül, mit csinált a modell.

**Az export fájlt töröl.** Az eredeti PDF-ek és képek az export elkészültével
törlődnek a szerverről (a cég beállíthat türelmi időt). A képernyő ezt előre
kimondja, és felkínálja az eredetik letöltését ZIP-ben. A sorrend a
`ExportKeszito`-ben kötött: előbb az export fájl, aztán a tételek átjelölése,
és csak ezután a törlés — fordítva egy félbemaradt export után a bizonylat is
odalenne, meg az adat is.

**A gépi és az emberi érték külön él.** A `document` oszlopai az ember
munkapéldánya; a modell nyers válasza a `document_extractions` sorban marad
érintetlenül, a kettő különbsége pedig jóváhagyáskor mezőnként a
`document_corrections` táblába kerül. Enélkül nem mérhető, hogy egy
prompt- vagy modellcsere javított-e a pontosságon.

**A saját cég adószáma csak a modellnek szól, következtetést nem építünk rá.**
Bekerül a promptba, hogy a kiolvasás tudja, melyik oldalon állunk — és ennyi. A
két lehetséges következtetés (a bizonylat idegen; a vevő neve igazolt) meg volt
írva, és mindkettő kikerült: az egyik hamis riasztást adott a termék fő
használójánál, a másik pedig egy alig észrevehető haszonért tartott életben egy
egész gondolatmenetet. Lásd a *Hátralék* pontot.

**A bizonytalanságot két jel adja.** A modell önbevallott magabiztossága
rosszul kalibrált, ezért mellette determinisztikus validátorok futnak
(adószám ellenőrző számjegye, `nettó + ÁFA = bruttó`, dátumsorrend,
pénznemkód, és az ÁFA-bontás soronkénti meg összegzett ellenőrzése), és ezek
**csak lefelé húzhatnak**: `Konfidencia::osszevon()`.

**A jelzés a képernyőn lévő értékre vonatkozik.** Az ellenőrző képernyő minden
körben újrafuttatja a validátorokat a *jelenlegi* űrlapállapoton
(`Ellenorzes::render()`), nem a kiolvasáskor tárolt verdiktet mutatja. Enélkül a
javított mező pirosan maradna, a frissen elrontott meg tisztán. A tárolt gépi
verdikt ettől érintetlen marad a `document_extractions` sorban — az az
audit-nyom, abból derül ki utólag, mit hibázott a modell.

**A szolgáltatás kizárólag vállalkozásoknak szól, és ez nem csak mondat.**
A fogyasztóvédelmi jog kógens: hiába köti ki az ÁSZF, ha a rendszer beenged egy
magánszemélyt, rá attól még a fogyasztói szabályok érvényesek (elállási jog,
békéltető testület). A gyakorlati szűrő az adószám — fogyasztónak nincs —,
ezért a cégnyitás **érvényes magyar adószámot** követel
(`App\Livewire\App\CegLetrehozas`). Itt szigorúbb a mérce, mint a
bizonylatokon: az `Adoszam` osztály alapból megengedő, mert egy külföldi
*szállító* adószáma nem magyar alakú és attól még helyes — az a szabály
viszont a partnerre szól, ez pedig a saját cégünkre.

**Az ÁFA-bontás szerkeszthető és exportálható.** A sorok összege a legerősebb
jelünk: ez fogja meg azt a hibát, amikor a modell egy tételsor összegét írja be
végösszegnek. Ezért az ember javíthatja is (`Ellenorzes::parseoltBontas()`), és a
javított bontás eljut az exportba: a táblázatos formátumok kulcsonkénti
oszlopokat kapnak (27/18/5/0/egyéb — `AfaBontas::vodrok()`), a JSON pedig a
teljes beágyazott listát, kategóriakóddal együtt. **A 0%-os soron talált ÁFA az
„egyéb" vödörbe kerül** — nullától nem keletkezik adó, és pénzt csendben elnyelni
nem szabad.

**A kézzel írott bizonylat külön eset.** Egy valódi számlán a modell helyesen
olvasta ki mindkét adószámot, a számlaszámot, mind a három dátumot és az
összegeket — a szállító nevét viszont **kitalálta**, és a beírt név semmiben nem
hasonlított a papíron állóra. A számtan hibátlan maradt (nulla ÁFA-nál nincs mit
elrontani), mindkét adószám átment az ellenőrző számjegyen, a névre pedig nincs
és nem is lehet determinisztikus ellenőrzésünk. Ezért kér a séma egy
`nehezen_olvashato` zászlót: nem a hibát találja meg, hanem kimondja, hogy ezen
az iraton semmiért nem tudunk jótállni. „Kézzel írott-e ez a papír" ellenőrizhető
tény — szemben a magabiztossággal, ami háromszor is használhatatlan volt.

**Az e-mailes beérkeztetés hitelesítetlen írási út.** Ezért három szabály nem
opcionális: a **címzett** tokenje dönti el a céget (soha nem a feladó),
e-mailből érkező irat soha nem kerül automatikusan jóváhagyásra, és a
feldolgozás idempotens (`message_id` cégenként egyedi).

### Árazás

A csomagok minden száma a `config/szamlafolyo.php`-ban áll — ár, darabkeret,
felhasználószám, darabár —, és a nyitólap árlistája, a Beállítások képernyő és a
keretszámolás mind onnan olvas. Marketingszövegbe kézzel beírt szám előbb-utóbb
elcsúszik attól, amit a rendszer valóban ad; az árlistán álló szám viszont
szerződéses ígéret.

|  | Start | Flow | Pro |
|---|---:|---:|---:|
| Havi nettó | 1 990 Ft | 4 990 Ft | 9 990 Ft |
| Dokumentum / hó | 50 | 200 | 500 |
| Felhasználó | 2 | 5 | korlátlan |
| Saját darabár (ár ÷ darab) | 39,80 Ft | 24,95 Ft | 19,98 Ft |
| Extra dokumentum | 49 Ft | 29 Ft | 24 Ft |

**Egy fiók egy cég**, egy kerettel és egy beküldési címmel. Egy könyvelőiroda
ezért az összes ügyfelét egy fiókban dolgozza fel, és az ügyfelenkénti
szétválasztást az export vevő-adószám szűrője adja meg — nem cégek
adminisztrálása. Ez tudatos csere: kevesebb fogalom a felhasználónak.

### Ügyfelenkénti export

Ez az a képesség, ami miatt egy könyvelőiroda **egyetlen** fiókban tudja
feldolgozni az összes ügyfelét. Az Export képernyő ügyfélszűrője
**adószám alapján** válogat, nem név szerint: a „Példa Kft.", „Példa Kft" és
„PÉLDA KFT" ugyanaz a cég, három sztring. Az illesztés a **törzsszámra** megy
(első nyolc jegy), mert ugyanaz a cég szerepelhet `11176165-2-10` és
`HU11176165` alakban is ugyanabban a hónapban — az ÁFA-kód és a megyekód
változhat, az adóalanyt a törzsszám azonosítja.

A **választható lista** a vevő oldalról áll össze: a könyvelő ügyfelei ott
állnak ismétlődően, a szállítók listája viszont minden beszállítót
tartalmazna, és nem az ügyfeleket mutatná. A **szűrés** viszont mindkét oldalt
nézi: a könyvelő ügyfele a bejövő számlán a vevő, a kimenőn a szállító, és aki
az ügyfelét választja, mindkettőt várja, nem a felét.

A szűrés a lekérdezés után, PHP-ben történik. A tárolt adószám formátuma
bizonylatonként más lehet, a törzsszámot csak normalizálás után lehet
összevetni — az időszak sorai pedig amúgy is a memóriában vannak, mert az
összesítés és az export ugyanazt a halmazt kapja.

Az elkészült export megőrzi, kire szűrtünk (`exports.filters`): utólag ez a
bizonyíték arra, mi került bele.

Próba: **14 nap vagy 50 dokumentum, amelyik előbb elfogy**, bankkártya nélkül —
pontosan egy Start-hónap. Húsz dokumentum volt itt korábban, abból viszont egy
könyvelőiroda egy óra alatt kifut, és úgy sosem jut el a termék lényegéig: a
kötegig, az ellenőrzésig, a havi exportig. A szűkítés indoka („az ingyen keret
AI-költséget generál") ezen a modellen nem áll — egy kiolvasás nagyságrendileg
fillér —, és a visszaélés ellen sem az véd, hanem hogy a próba céghez kötött és a
fájlok hét nap után törlődnek.

**Az árlépcsőnek két invariánsa van**, mindkettőt élesben rontottuk el egyszer, és
mindkettőt teszt őrzi (`tests/Unit/ArlepcsoTest.php`):

1. Az extra darabár **mindig drágább** a csomag saját darabáránál. Különben azt
   tanítjuk, hogy megéri a kis csomagban maradni és túllépni.
2. A saját darabár **csomagról csomagra csökken**. Enélkül a felfelé lépésnek
   nincs értelme. Ezen bukott meg a „Pro maradjon 14 990 Ft, csak 500 dokumentum"
   ötlet: az 29,98 Ft/darab lett volna, drágább a Flow-nál.

Egyik szám sem látszik a képernyőn, csak abban, hogy senki nem vált csomagot.

**Éves fizetés szándékosan nincs.** A keret a Stripe számlázási ciklusára szól
(`Kvota::idoszak()`), egy éves előfizetésnél tehát tizenkét hónapos ablakot
kapna: évi ötven dokumentum a havi ötven helyett, tizenhat százalék
kedvezményért. Havi keret + éves számlázás csak külön forgó ablakkal működne;
amíg az nincs meg, csak havi árat adunk el. Ha valaki mégis felvenne egy éves
árat, az `ArazasTest::test_nincs_eves_arazonosito_a_csomagokban` bukik el, és ott
olvassa el, miért.

**A kiírt ár és a terhelt ár nincs egymáshoz kötve.** A képernyőkön a config
számai állnak, a pénzt a Stripe árai mozgatják — ha elcsúsznak, minden teszt zöld
marad, minden képernyő helyesnek látszik, és az eltérésről a vevő a számláján
értesül. Az `arak:ellenoriz` parancs veti össze a kettőt (összeg, pénznem,
egyszeri/ismétlődő, archivált-e); **telepítés után futtatni kell.**

**A keret kreditben fogy, nem sorban.** A vevő dokumentumot vásárol, a
költségünk viszont oldalarányos: egy nyolcvan oldalas köteg nem egy nyugta. Az
első öt oldal egy dokumentum, e fölött minden megkezdett öt oldal még egy
(`App\Support\Kredit`, `szamlafolyo.kredit.oldal_per_kredit`). A szabály
szándékosan egyszerű, mert **ki van írva** — a nyitólapon és a Beállításokban is.
Egy normál számla (1–3 oldal) így biztosan egy marad: a fair-use szabály nem
érintheti a hétköznapi használatot, különben nem fair-use, hanem rejtett
áremelés.

**Korlátlan csomag nincs**, és korábban véletlenül volt: aktív előfizetés
ismeretlen árazonosítóval `PHP_INT_MAX` keretet kapott. Egy Stripe-ban
létrehozott, de az `.env`-be be nem írt ár így csendben korlátlan AI-használatot
nyitott — épp a legdrágább oldalon. Ismeretlen ár mostantól a legkisebb csomag
keretét kapja, és `warning` szinten a naplóba kerül.

**A keret fölött alapból megállunk.** A tulajdonos a Beállításokban külön
bekapcsolhatja a darabonként számlázott feldolgozást; addig a beküldött iratok
megvárják a következő időszakot. Váratlan számlát senki ne kapjon attól, hogy egy
hónapban többet dolgozott — a kapcsolás ezért külön naplóbejegyzést is kap.

**És az engedély sem nyitott végű: van forintban mért plafon**
(`companies.overage_limit_ft`). Enélkül egy elgépelt tömeges feltöltés
tetszőleges összeget tudna a következő számlára tenni. A plafon nem opcionális
kényelmi mező: a bekapcsolás magától beírja a
`szamlafolyo.tulhasznalat.alap_plafon_ft` értékét, mert aki nem tud a mezőről,
azt is védenie kell. Kiüríteni lehet — az `null`, vagyis nincs felső határ —, de
csak tudatosan, és az is a naplóba kerül.

A terhelést a napi `tulhasznalat:elszamol` viszi fel a következő Stripe-számlára,
**nem** a feldolgozás közben: egy hálózati hiba miatt nem maradhat feldolgozatlan
egy bizonylat. A parancs mindig a ténylegesen túllépett és a már kiszámlázott
kreditek különbségét terheli (`overage_charges` tábla), ezért egy megszakadt vagy
megismételt futás nem terhel kétszer.

### A paletta

A felület meleg, földszínű: krémszín papír, terrakotta kiemelés, DM Sans. A
képernyők viszont száz helyen hivatkoznak `slate-` és `blue-` osztályokra,
ezért nem azokat írtuk át egyesével — az a fajta változtatás mindig hagy maga
után egy elfelejtett kék gombot valahol —, hanem **magát a két Tailwind-skálát**
definiáltuk újra a `resources/css/app.css` `@theme` blokkjában. A nevük
szándékosan maradt: a jelentésük „semleges" és „kiemelt", nem az, hogy szürke
és kék.

A konfidencia három színe (`biztos`, `bizonytalan`, `gyanus`) **nem** követi ezt
a hangolást. A figyelmeztetés akkor ér valamit, ha kilóg a környezetéből.

## Hátralék

**A három jogi oldal szövege kész** (ÁSZF, adatkezelési tájékoztató,
impresszum), és a számaik a configból jönnek: ár, darabkeret, felhasználószám,
próbaidő, megőrzési plafon, postafiók-takarítás, a kiolvasó modell neve. Az
árlistán egy elcsúszott szám kellemetlen, egy szerződésben viszont az ígéret
csúszik el a teljesítéstől — az adatkezelésiben pedig a tájékoztató kezd
hazudni, nem a program. Amit kézzel kell átírni, az a hatálybalépés dátuma
(`$hatalyos` a `resources/views/jogi/` alatt). A `config()` és nem `env()`
ott külön is számít: nézetben hívott `env()` a `config:cache` után `null`.

**Az ÁSZF és az adatkezelési tájékoztató nem esett át jogi
felülvizsgálaton.**

**A Google Fonts a látogató IP-címét a Google-höz küldi.** A betűtípusok ma a
Google szerveréről töltődnek (`components/betukeszlet.blade.php`), így minden
oldalletöltés adattovábbítás egy amerikai szolgáltatóhoz — az adatkezelési
tájékoztató 6. pontja ezt ki is mondja. A megoldás a betűk helyben tárolása:
két woff2 fájl a `public/` alá és egy `@font-face` az `app.css`-be, utána a
tájékoztató 6. pontja törölhető. Addig a szöveg legalább igaz.

**Az OpenRouter felé nincs kikötve az adatmegőrzés tiltása.** A kérésbe
felvehető a `provider.data_collection = "deny"`, amivel a kiolvasás csak olyan
szolgáltatóhoz kerülhet, amelyik nem tárolja a tartalmat — ettől a
tájékoztató erősebbet is ígérhetne, mint amit ma ígér. Cserébe szűkül a
választható szolgáltatók köre, ezért mérés nélkül nem kapcsoltuk be.

**Irodai csomag.** Ma egy fiók egy céget kezel, és az árlista is így szól. Egy
könyvelőiroda ezért egyetlen fiókban dolgozza fel az összes ügyfelét, az
ügyfelenkénti szétválasztást pedig az export adószámszűrője adja meg. Ez
működik, és egyszerű. A cégenkénti szétválasztás (több cég egy előfizetés
alatt, közös kerettel) meg volt építve cégváltóval együtt, és **tudatosan lett
visszabontva**: a felhasználónak cégeket kellett volna adminisztrálnia ahhoz,
hogy szétválassza, amit egy szűrő is szétválaszt. A `git log` őrzi (`098310d`,
`4f59ab4`), ha egyszer valódi igény lesz rá.

**Az „idegen bizonylat" ellenőrzés kétszer került be és kétszer ki**
(`5a710fb` → `5ea25ab`, `bb4d6c9` → itt). Mindkétszer ugyanazon bukott el: a
termék fő használója sok ügyfél iratát dolgozza fel egy helyen, ott pedig
egyetlen bizonylat sem a saját cégnek szól, tehát a jelzés a *rendes*
működésre tüzelne. Egy validátor, ami jogos munkamenetre szólal meg, rosszabb
a semminél — viszi magával a többi piros súlyát is.

Vele ment a mentesítő párja is (a vevő neve „igazolt", ha a vevő adószáma a
miénk), pedig az sosem adott hamis riasztást. Nem azért, mert rossz volt, hanem
mert egyedül maradt: egy egész gondolatmenetet — saját adószám, törzsszám,
igazolt mezők csatornája a `Konfidencia`-ban — tartott volna életben egyetlen
sárga keret kedvéért. **A termék attól jó, hogy egy könyvelő tanulás nélkül
használja**, és minden fogalomnak, ami benne marad, meg kell szolgálnia a
helyét. Akkor lesz értelme visszahozni, ha a rendszer a feldolgozás *előtt*
köti ügyfélhez a bizonylatot; addig a kiolvasott adószám az exportnál
válogat, nem az ellenőrzésnél vádol.

## Felépítés

| Hol | Mi |
|---|---|
| `app/Support/` | Tiszta függvények: magyar összegformátum, adószám ellenőrző számjegy, dátumértelmezés, bérlő |
| `app/Services/Extraction/` | Prompt (verziózva), séma, OpenRouter-hívás, validátorok, konfidencia, sorkezelő |
| `app/Services/Export/` | Oszlopdefiníciók egy helyen + xlsx/csv/json író |
| `app/Support/AfaBontas.php` | Az ÁFA-bontás számtana: kulcsértelmezés, származtatott bruttó, kulcsonkénti összegzés |
| `app/Services/Ingest/` | Címzett-token feloldás és IMAP-olvasó |
| `app/Services/Billing/` | Keretszámolás és Stripe |
| `app/Livewire/` | A nyolc képernyő |
| `resources/views/nyitolap.blade.php` | A nyilvános oldal (`/`), a próbaidő és a csomagok számait a konfigurációból véve |
| `resources/views/jogi/` | ÁSZF, adatkezelés, impresszum — hitelesítés nélkül is olvashatók |
| `design/logo/` | A logó designcsomagja, ahogy érkezett: a jel geometriája és a márkaszínek forrása |
| `resources/css/app.css` | A dizájnrendszer — minden ismétlődő elem itt van definiálva egyszer |
| `lang/hu/validation.php` | A validációs üzenetek magyarul. Enélkül a képernyőn a nyers kulcs jelent meg (`validation.required`) |

## Fejlesztés

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build          # vagy: npm run dev
php artisan serve
```

Tesztek (PostgreSQL ellen futnak, mint az éles rendszer — a CI konkrétan
`postgres:11` ellen, PHP 8.2-n és 8.4-en is):

```bash
php vendor/bin/phpunit
vendor/bin/pint
```

## Telepítés a nethely tárhelyre

### Előbb egy csapda: a `php` nem az, aminek látszik

Ezen a tárhelyen **az SSH alapértelmezett PHP-je 7.4, a webcímé viszont 8.4**.
A Laravel 12 PHP 8.2+-t kér, tehát a csupasz `php artisan` vagy `composer install`
a rossz értelmezőt indítaná — és ez a fajta hiba a legdrágább: a `composer` régi
csomagverziókat oldana fel, az `artisan` fatalt dobna, a **cron pedig némán nem
csinálna semmit**. Az első visszajelzés az lenne, hogy egy ügyfél e-mailben
beküldött bizonylata nem jelenik meg.

Ezért a `deploy.sh` **maga keresi meg** a megfelelő PHP-t (`php8.4`, `ea-php84`,
`/opt/php*/bin/php` és társai), és megáll, ha nem talál 8.2+-t. Megnézni,
mit választana:

```bash
./deploy.sh --check          # csak kiírja, melyik PHP-t használná
PHP_BIN=/eleresi/ut/php ./deploy.sh    # ha te tudod jobban
```

A függőségfa szándékosan **PHP 8.2-re van feloldva** (`composer.json` →
`config.platform`), hogy bármelyik 8.x-en telepíthető legyen — nem csak azon,
amit épp a webcím használ.

### A második csapda: az SSH home nem az FTP gyökere

A nethely-n a két belépési pont **más könyvtárba érkezik**:

```
/var/www/customers/<azonosito>/web        ← ide lép be az SSH ($HOME)
/var/www/customers/<azonosito>/web/home   ← ezt látja az FTP és a vezérlőpult
```

A vezérlőpult könyvtár-tallózója csak az utóbbi fát mutatja, ezért **a projektnek
a `~/home` alá kell kerülnie** — ha a `~`-ba klónozod, a docroot beállításánál
egyszerűen nem lesz kiválasztható:

```bash
git clone https://github.com/spyker55/SzamlaFolyo.git ~/home/szamlafolyo
cd ~/home/szamlafolyo
```

Ha már máshová klónoztad, elég átmozgatni (`mv ~/szamlafolyo ~/home/szamlafolyo`),
majd az új helyről lefuttatni a `./deploy.sh`-t — a gyorsítótárak abszolút
útvonalakat tárolnak, ezért a költöztetés után újra kell építeni őket. Ezt a
szkript magától megteszi.

**A sorrend itt számít.** Amíg a docroot a projekt fölötti könyvtáron áll, a
projekt teljes tartalma webről elérhető — az `.env`-fel együtt, amiben az
adatbázis-jelszó, az OpenRouter-kulcs és az `APP_KEY` van. Ezért van a projekt
gyökerében egy `.htaccess`, ami **fájlnév szerint** tiltja az `.env`-et és néhány
telepítési fájlt: ezekre egyetlen jogos kérés sem irányul, tehát jó beállításnál
sem zavarnak, rossznál viszont 403 jön a titkok helyett. Költöztetés után **azonnal** állítsd át a docrootot. Az ellenőrzés az, hogy a
bejelentkező oldal betöltődik-e — a `.env` lekérését *ne* próbáld ki curl-lel, mert azt a
szolgáltató tűzfala támadásnak minősíti (lásd a harmadik csapdát).

### A harmadik csapda: a szolgáltató tűzfala

Ha minden be van állítva, és az oldal mégis **403**-at ad — mindenhonnan, laptopról,
mobilnetről és VPN-en át is —, akkor nézd meg, **kinek a hibaoldala** jön vissza. Ez a
megkülönböztető jel:

| Amit látsz | Mi az |
|---|---|
| A szolgáltató márkázott hibaoldala („A kérést a tűzfalunk elutasította") | a szolgáltató webalkalmazás-tűzfala (WAF) |
| Csupasz Apache 403, tartalom nélkül | egy `.htaccess` tiltás — lásd alább |
| Laravel hibaoldal | a mi kódunk |

**Előbb a saját `.htaccess`-t vedd ki a képből**, mert a márkázott hibalap megtévesztő: a
szolgáltatók minden 403-ra a saját lapjukat szolgálják ki, akkor is, ha a tiltás a te
fájlodból jön. A próba harminc másodperc:

```bash
cd ~/home/szamlafolyo && mv .htaccess .htaccess.ki      # majd töltsd újra az oldalt
```

Ez azért reális gyanú, mert **az Apache nem a DocumentRoottól kezdi a `.htaccess` fájlok
olvasását**, hanem attól a könyvtártól, ahol az `AllowOverride` engedélyezve van — osztott
tárhelyen ez tipikusan a fiók gyökere, jóval a docroot fölött. Egy `Require all denied` a
projekt gyökerében így a helyesen beállított oldalt is megölheti. (Ez meg is történt: a
mostani, fájlnév szerint szűrő változat pont ezért lépett a mindent tiltó helyébe.)

Ha a fájl nélkül is 403 jön, akkor tényleg a WAF: a vezérlőpult fejlécében lévő **„Tűzfal
tanuló mód"** a megoldás, illetve ügyfélszolgálati kérés a szabály feloldására. A saját
kódodban ilyenkor hiába keresed a hibát — a kérés el sem jut a PHP-ig.

**Amire később számíts.** A WAF-ok pont azokra a kérésekre ugranak rá, amikből ez az
alkalmazás él: a Livewire minden interakciót JSON-törzsű `POST`-ként küld a
`/livewire/update` végpontra, a feltöltés több megabájtos bináris `multipart/form-data`,
a Beérkező pedig másodpercenként kérdez vissza, amíg feldolgozás folyik. Ha ezek közül
bármelyik „ok nélkül" hibázik, **előbb a tűzfal naplóját nézd, ne a kódot.**

**Amit ne csinálj:** ne kérd le a `.env`-et curl-lel a szervertől annak ellenőrzésére, hogy
zárva van-e. Pont ez az a kérés, amit minden WAF támadásnak minősít — és emiatt átmenetileg
a fejlesztői géped IP-jét is kitilthatja. A docroot helyessége abból látszik, hogy a
bejelentkező oldal betöltődik.

### Lépések

1. A webcím **gyökérkönyvtára a projekt `public/` mappájára** mutasson
   (a tallózóban: `szamlafolyo/public`), a PHP verziója **8.4**
   (nethely admin → Webcím → PHP verzió).
2. `.env` feltöltése a `.env.example` alapján, majd `<php> artisan key:generate`.
3. `./deploy.sh` — megkeresi a PHP-t, telepít, ellenőrzi a környezetet, migrál,
   gyorsítótáraz, és a végén **kiírja a három cron sort a helyes elérési úttal**.
4. A kiírt három időzített feladat felvétele (lásd lentebb).
5. `<php> artisan arak:ellenoriz` — összeveti a kiírt árakat a Stripe-ban
   beállítottakkal. Ez az egyetlen hely, ahol a config számai és a valóban
   terhelt összegek összeérnek; amíg eltérnek, a felület mást ígér, mint amit
   levonunk.

### Időzített feladatok

A nethely „Időzített folyamatok" felülete űrlap, nem crontab-sor: az időzítés külön
mezőkbe megy, a parancs pedig a **Kezelő → „Egyedi parancs"** mezőbe. Ne a
„Parancssori php-cli"-t válaszd: az a vezérlőpult saját PHP-jét használná, ami itt
7.4, azon pedig az alkalmazás el sem indul.

| Mit csinál | Időzítés | Parancs |
|---|---|---|
| Beérkeztetés e-mailből | `*/5 * * * *` | `<php> <projekt>/artisan email:beolvas` |
| Kiolvasás, elakadt futások | `*/5 * * * *` | `<php> <projekt>/artisan dokumentum:feldolgoz --limit=5` |
| Selejtezés (fájlok és régi levelek) | `17 3 * * *` | `<php> <projekt>/artisan fajl:selejtez` |
| Túlhasználat elszámolása | `41 4 * * *` | `<php> <projekt>/artisan tulhasznalat:elszamol` |

**Átirányítás (`> /dev/null`) ne legyen bennük, és értesítési címet érdemes
megadni a vezérlőpultban.** A tárhely időzítője minden kimenetet e-mailben küld
el, az öt percenként futó parancsok pedig napi 288 levelet jelentenének — két hét
alatt mindenki szűrőt tesz rájuk, és utána a valódi hibáról szóló levél is a
szűrőbe esik. A kézenfekvő `> /dev/null` viszont **rosszabb**: a Laravel a
hibaüzenetet is a standard kimenetre írja (a `$this->error()` és a lefutó kivétel
is), tehát az átirányítás épp a bajt nyelné el, és a cron némán romlana el.

Ezért a parancsok maguk hallgatnak, ha nincs mondanivalójuk
(`App\Console\Commands\Concerns\CsendesCron`): terminálból futtatva kiírják az
összegzést, cronból nem, hibát viszont mindig. Így az értesítési címre **csak
akkor érkezik levél, ha tényleg baj van**. Ugyanezért nem hiba a hiányzó
beérkeztető postafiók sem: az beállítás, nem üzemzavar, tehát a napi takarítás
nem szól érte (`PostafiokOlvaso::beallitva()`).

A parancsban **nincs `cd` és nincs `&&`**. Az `artisan` a saját helyéből (`__DIR__`)
oldja fel az útvonalakat, ezért abszolút úttal hívva bármelyik munkakönyvtárból
ugyanúgy fut — a shell-operátorokat viszont egyes vezérlőpultok nem értelmezik, és
akkor a feladat némán nem csinál semmit. Nyers crontabban a két oszlop egyszerűen
egymás után kerül.

A pontos értékeket a `./deploy.sh` írja ki a futása végén. Mielőtt felveszed őket,
próbáld ki egyszer kézzel — a cron hibája néma:

```bash
cd /tmp && <php> <projekt>/artisan dokumentum:feldolgoz --limit=1
```

A `cd /tmp` szándékos: pont azt bizonyítja, hogy a parancs a munkakönyvtártól
függetlenül működik.

### Az e-mailes beérkeztetés bekapcsolása

Négy dolog kell hozzá, és mind a négy nélkül a cím **működőnek látszik, de a
levél sehova nem érkezik meg** — a feladó sem kap hibát. A Beállítások képernyő
ezért ki is írja, ha a postafiók még nincs beállítva.

**A rövidebb út a plusz-címzés**, mert ehhez DNS-t sem kell módosítani: a fődomén
MX-e már megvan. Egy sima postafiók kell (`bekuldes@<domain>`), és:

```
INBOX_MODE=plus
INBOX_PLUS_ADDRESS=bekuldes@<domain>
IMAP_HOST=…  IMAP_USERNAME=…  IMAP_PASSWORD=…
```

A cím így `bekuldes+<token>@<domain>` lesz. Két perc alatt kipróbálható: küldj
levelet a `bekuldes+teszt@<domain>` címre, és nézd meg, megjön-e a fiókba. (A
nethelynél működik.)

**A catch-all aldomén a másik út**, szebb címekkel (`<token>@bekuldes.<domain>`),
de három dolgon múlik egyszerre:

1. **MX-rekord** az aldoménre — **az MX nem öröklődik**, a fődoméné nem
   vonatkozik rá. Ha van `*` A rekord, az aldomén feloldódik a webszerverre, és a
   levél oda próbál kézbesíteni, majd visszapattan: néma hiba.
2. **Catch-all postafiók** az aldoménre (`*@bekuldes.<domain>` → egy fiók).
3. `INBOX_MODE=catchall` és `INBOX_DOMAIN=bekuldes.<domain>`.

Mindkét úthoz kell még a `email:beolvas` az időzített feladatok közé. A
`Feldolgozott` és a `Besorolatlan` mappát a parancs maga létrehozza, ha a
szolgáltató engedi.

A cégek beküldési címe a Beállítások képernyőn látszik, és a
`email:beolvas --proba` is kiírja mindet.

### Ha nem érkezik meg az e-mailben küldött számla

A bejelentés öt különböző dolgot jelenthet, és a felületen egyik sem látszik.
Ez a parancs megkülönbözteti őket — **semmit nem jelöl olvasottnak és nem
mozgat**, tehát akkor is nyugodtan futtatható, ha a cron már átment a fiókon:

```bash
<php> <projekt>/artisan email:beolvas --proba
```

Kiírja a cégek beküldési címét, a fiók mappáit, és a legutóbbi levelekre
soronként, hogy mely címeket találta a fejlécekben, kijött-e belőlük token, van-e
hozzá cég, és mely mellékleteket fogadná el.

| Amit mutat | Hol a hiba |
|---|---|
| „A postafiók nem érhető el" | `IMAP_HOST`, `IMAP_USERNAME`, `IMAP_PASSWORD` |
| „A(z) … mappa nem létezik" | `IMAP_FOLDER` — a kiírt mappalistából válassz |
| „A(z) … mappa üres" | a levél be sem jött: MX-rekord, catch-all átirányítás, spam mappa |
| „nincs érvényes beküldési token" | rossz címre ment, vagy a továbbküldés levágta a fejlécet |
| „ehhez nincs cég" | a token jó alakú, de nem szerepel az adatbázisban |
| „nincs feldolgozható melléklet" | nem támogatott fájltípus (docx, zip) |

Ha a mappa üresnek látszik, mert a cron már átmozgatta a leveleket, nézz bele a
feldolgozottakba is: `IMAP_FOLDER=Feldolgozott <php> <projekt>/artisan email:beolvas --proba`.

A besorolatlan levél a `Besorolatlan` mappába kerül (`IMAP_UNMATCHED_FOLDER`), és
`warning` szinten a naplóba is bekerül a megvizsgált címekkel — a feldolgozottak
közé keverve pont az veszne el, amit keresni kell.

### Megőrzési idő

Egyetlen postafiókba érkezik minden cég beküldése; a tokent a *címzés* hordozza,
a szétosztás az alkalmazásban történik. Ebből következik, hogy a fiók magától
nem ürül — és a benne álló levél a **melléklettel együtt** ugyanannak a
számlának egy teljes másolata. Enélkül a takarítás nélkül két baj van: betelik a
tárhely, és hazuggá válik a fájlok törlése is, hiszen a másolat túléli.

Ezért a `fajl:selejtez` (napi egyszer) a fiókot is takarítja:

| Mit | Alap | Beállítás |
|---|---|---|
| Eredeti fájlok export után | 0 nap, **legfeljebb 7** | Beállítások képernyő cégenként |
| `Feldolgozott` mappa | 7 nap | `INBOX_KEEP_DAYS` |
| `Besorolatlan` mappa | 14 nap | `INBOX_UNMATCHED_KEEP_DAYS` |

Három szabály, amitől ez nem tud kárt okozni:

- **A beérkező mappát soha nem takarítjuk.** Egy elgépelt
  `IMAP_PROCESSED_FOLDER=INBOX` különben a még fel nem dolgozott leveleket
  vinné el, némán. (`PostafiokOlvaso::takarithato()`, saját teszttel.)
- **A `0` nap kikapcsolás**, mindkét mappára külön.
- A feldolgozottakra a fájlok plafonja is vonatkozik (`Company::MEGORZES_MAX_NAP`,
  7 nap): ott ugyanaz az irat fekszik, ami már bent van az alkalmazásban, tehát
  nem élhet tovább nála. A `Besorolatlan` kap hosszabb türelmi időt, mert abból
  **nem lett** irat — az az egyetlen példány, és emberi ránézést kér.

A megőrzés az `InboundEmail` sorra (feladó, tárgy, `message_id`) nem vonatkozik:
az néhány száz bájt, és ez az idempotencia alapja — nélküle ugyanaz a levél
újrakézbesítve második tételt csinálna.

### Ellenőrzés telepítés után

```bash
<php> artisan kornyezet:ellenoriz
```

Végigmegy a PHP-verzión, a kötelező kiterjesztéseken, az adatbázis-kapcsolaton
(kiírja a **szerver** verzióját is, nem csak a `psql` kliensét), a könyvtárak
írhatóságán és azon, hogy a külső szolgáltatások be vannak-e állítva. Hiba
esetén nem nulla kilépési kóddal áll meg, ezért a `deploy.sh` is ráfut.

Ez a parancs a **CLI** PHP-t nézi. A webes PHP-ra a próba egyszerűbb: ha a
bejelentkező oldal betöltődik és a regisztráció + cégnyitás lefut, megvan minden
kiterjesztés, ami kell.

### A kiolvasás próbája parancssorból

```bash
<php> artisan kiolvasas:proba ~/szamla.pdf
```

Végigfuttatja a teljes kiolvasást egy bizonylaton, és kiírja, mit ismert fel a
modell, mekkora magabiztossággal (ugyanazzal a három színsávval, mint az
ellenőrző képernyő), mit kifogásoltak az ellenőrzések, mennyi tokenbe és
mennyi pénzbe került, és **melyik modellt futtatta ténylegesen** a szolgáltató.

Két dologra való. Telepítéskor ez a leggyorsabb út annak eldöntéséhez, hogy a
kulcs, a modellnév és a PDF-formátum stimmel-e — és mivel nem megy át a
webrétegen, akkor is használható, amikor a felület valamiért nem érhető el.
Tartósan pedig ez a mérőeszköz: prompt- vagy modellcsere után ugyanazon a
bizonylaton összehasonlítható, javult-e a pontosság (a kimenet ezért írja ki a
prompt verzióját is).

**A próbafájl ne a `public/` mappába kerüljön.** A webcímhez tartozó FTP-fiók
éppen oda lép be, tehát oda a legkönnyebb feltölteni — csakhogy az a webgyökér,
és onnan a bizonylat bárkinek letölthető. Tedd a home könyvtárba
(`~/szamla.pdf`); ha csak az az FTP-fiók áll rendelkezésre, feltöltés után SSH-ból
mozgasd arrébb:

```bash
mv ~/home/szamlafolyo/public/szamla.pdf ~/szamla.pdf
```

A parancs egyébként szól, ha webről elérhető helyen lévő fájlt kap — de lefut,
mert a figyelmeztetés nem tiltás.

Cég nélkül is működik: ha még egyet sem nyitottál (a cégnyitás a webfelületen
történne, ami épp lehet elérhetetlen), a parancs csinál egy ideiglenest, és a
végén el is takarítja. Éles méréshez add meg a valódi cégnevet — a modell ebből
tudja eldönteni, melyik fél a partner a bizonylaton:

```bash
<php> artisan kiolvasas:proba ~/szamla.pdf --ceg-nev="Példa Kereskedelmi Kft."
```

Két modell összemérése ugyanazon a bizonylaton — ez az árazási döntés
alapja, mert a kimenet a költséget és a mezőnkénti magabiztosságot is kiírja:

```bash
<php> artisan kiolvasas:proba ~/szamla.pdf --modell="anthropic/claude-sonnet-5"
```

Az alapértelmezett modell a **`google/gemini-3.8-flash`**.

Gépi nyomtatású számlákon a nála négyszer olcsóbb Flash Lite is ugyanazt adta,
mint a Claude Sonnet 5 — **kézzel írott** bizonylaton viszont megbukott:
háromszor kiolvasva három különböző, kitalált szállítónevet adott („Süli
János", „Sién János", végül „Süni Fúró" — ez utóbbit láthatóan a tételsor
szövegéből, a *Betonfuratok készítése*-ből gyúrta). A Pro drágább és ötször
lassabb a Sonnetnél, ezért a köztes fok az alapértelmezés.

**A magabiztosság modellfüggő**, és ezt sokáig elnéztük. A Flash Lite-é
használhatatlan: 0,5 egy jól kiolvasott mezőre, majd 1,00 három különböző
kitalált névre — a legmagabiztosabb állítása volt a leghamisabb. A Sonnet 0,95-öt
adott az egyetlen tévedésére.

A 3.8 Flash viszont ugyanezen a kézzel írott számlán, **két futásból kétszer a
szállító nevére adta a lap legalacsonyabb értékét** (0,70 és 0,85) — pontosan az
egyetlen rossz mezőre —, a többire 0,90–0,99-et. És mindkétszer beállította a
`nehezen_olvashato` zászlót, amit a Lite egyszer sem tett meg. A név a második
futásra „Siéri László" lett: a keresztnév már helyes, a vezetéknév első betűje
nem — vagyis félreolvasás, nem kitalálás.

Harmadszorra viszont ugyanez a 3.8 Flash **0,85 fölé** tette ugyanezt a mezőt,
és közben harmadik változatban írta le a nevet. Hat kiolvasás, **hat különböző
szállítónév, egyik sem helyes** — miközben az adószámok, az összegek és a
dátumok mind a hatszor ugyanazok és helyesek voltak.

Ebből tehát nem az következik, hogy a jobb modell megoldotta: a mezőnkénti
magabiztosság kétszer eltalálta a rossz mezőt, harmadszorra nem. Kettő a
háromból nem védelem.

Amit viszont a 3.8 Flash **mind a háromszor** helyesen jelzett, az a
`nehezen_olvashato` zászló. Ez a megbízható jel ezen a papíron — dokumentum-,
nem mezőszinten. Ezért ha a zászló áll, az **ellenőrizhetetlen mezők** (nevek,
bizonylatszám, fizetési mód) nem látszhatnak biztosnak: `Konfidencia`
lehúzza őket a sárga sávba. Nem az összeset — aminek van független fogása
(adószám ellenőrző számjegye, `nettó + ÁFA = bruttó`), az a kézíráson is
maradhat jelöletlen.

A determinisztikus validátor marad a megbízhatóbb jel, és az összevonás
továbbra is **csak lefelé húzhat**.

A `--modell` futásidőben ír felül, nem környezeti változóval: élesben a
konfiguráció gyorsítótárazva van (`config:cache`), ott az `OPENROUTER_MODEL=…`
előtag már nem érvényesülne.

A próba **valódi modellhívás, tehát valódi pénzbe kerül.** A vizsgált iratot
alapból törli maga után, hogy ne szemetelje tele a Beérkezőt és ne fogyassza a
cég keretét; a `--megtart` kapcsolóval bent marad ellenőrzésre.

A frontend eszközök **le vannak fordítva a repóban** (`public/build`), mert a
tárhelyen nincs Node.js. Ha a CSS-t vagy a JS-t módosítod, futtasd az
`npm run build`-ot, és kommitold az eredményt is — a CI ezt ellenőrzi.

### Amit a szolgáltatónál be kell állítani

- **PostgreSQL** adatbázis. A tárhelyen ez ma **11.22**. Az alkalmazás elmegy
  rajta — a séma nem használ 12+ elemet, a Laravel séma-értelmezője pedig
  kifejezetten kezeli a 12 alatti verziót —, és a CI is `postgres:11` ellen fut,
  hogy ez bizonyított maradjon. Amit tudni kell: a PostgreSQL 11 2023 novembere
  óta **lejárt támogatású**, biztonsági javítást nem kap. Érdemes rákérdezni a
  szolgáltatónál, terveznek-e frissítést.
- **Catch-all postafiók** egy aldoménre (`*@bekuldes.<domain>` → egy fiók).
  Ha nincs catch-all, `INBOX_MODE=plus` mellett plusz-címzés is működik.
- **PHP 8.2+** a webcímhez, `pdo_pgsql`, `mbstring`, `intl`, `gd`, `zip`,
  `fileinfo` kiterjesztésekkel.

### Külső szolgáltatások

- **OpenRouter** — a kiolvasás. `OPENROUTER_API_KEY` és `OPENROUTER_MODEL`.
- **Stripe** — előfizetés. A csomagok darabkerete a `config/szamlafolyo.php`-ban
  van az ár-azonosítókhoz kötve; a webhook címe `/stripe/webhook`.
