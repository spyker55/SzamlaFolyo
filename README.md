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

**A bizonytalanságot két jel adja.** A modell önbevallott magabiztossága
rosszul kalibrált, ezért mellette determinisztikus validátorok futnak
(adószám ellenőrző számjegye, `nettó + ÁFA = bruttó`, dátumsorrend,
pénznemkód), és ezek **csak lefelé húzhatnak**: `Konfidencia::osszevon()`.

**Az e-mailes beérkeztetés hitelesítetlen írási út.** Ezért három szabály nem
opcionális: a **címzett** tokenje dönti el a céget (soha nem a feladó),
e-mailből érkező irat soha nem kerül automatikusan jóváhagyásra, és a
feldolgozás idempotens (`message_id` cégenként egyedi).

## Felépítés

| Hol | Mi |
|---|---|
| `app/Support/` | Tiszta függvények: magyar összegformátum, adószám ellenőrző számjegy, dátumértelmezés, bérlő |
| `app/Services/Extraction/` | Prompt (verziózva), séma, OpenRouter-hívás, validátorok, konfidencia, sorkezelő |
| `app/Services/Export/` | Oszlopdefiníciók egy helyen + xlsx/csv/json író |
| `app/Services/Ingest/` | Címzett-token feloldás és IMAP-olvasó |
| `app/Services/Billing/` | Keretszámolás és Stripe |
| `app/Livewire/` | A nyolc képernyő |
| `resources/css/app.css` | A dizájnrendszer — minden ismétlődő elem itt van definiálva egyszer |

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

### Időzített feladatok

A nethely „Időzített folyamatok" felülete űrlap, nem crontab-sor: az időzítés külön
mezőkbe megy, a parancs pedig a **Kezelő → „Egyedi parancs"** mezőbe. Ne a
„Parancssori php-cli"-t válaszd: az a vezérlőpult saját PHP-jét használná, ami itt
7.4, azon pedig az alkalmazás el sem indul.

| Mit csinál | Időzítés | Parancs |
|---|---|---|
| Beérkeztetés e-mailből | `*/5 * * * *` | `<php> <projekt>/artisan email:beolvas` |
| Kiolvasás, elakadt futások | `*/5 * * * *` | `<php> <projekt>/artisan dokumentum:feldolgoz --limit=5` |
| Lejárt fájlok selejtezése | `17 3 * * *` | `<php> <projekt>/artisan fajl:selejtez` |

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
