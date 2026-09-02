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

Tesztek (PostgreSQL ellen futnak, mint az éles rendszer):

```bash
php vendor/bin/phpunit
vendor/bin/pint
```

## Telepítés a nethely tárhelyre

1. A webcím **gyökérkönyvtára a projekt `public/` mappájára** mutasson.
2. `.env` feltöltése a `.env.example` alapján, majd `php artisan key:generate`.
3. `./deploy.sh` — ez húzza a kódot, telepít, migrál és gyorsítótáraz.
4. Három cron sor:

```
*/5 * * * * cd ~/szamlafolyo && php artisan email:beolvas
*/5 * * * * cd ~/szamlafolyo && php artisan dokumentum:feldolgoz --limit=5
17 3  * * * cd ~/szamlafolyo && php artisan fajl:selejtez
```

A frontend eszközök **le vannak fordítva a repóban** (`public/build`), mert a
tárhelyen nincs Node.js. Ha a CSS-t vagy a JS-t módosítod, futtasd az
`npm run build`-ot, és kommitold az eredményt is — a CI ezt ellenőrzi.

### Amit a szolgáltatónál be kell állítani

- **PostgreSQL** adatbázis (vagy MySQL 8, ha az adott csomagon nincs Postgres).
- **Catch-all postafiók** egy aldoménre (`*@bekuldes.<domain>` → egy fiók).
  Ha nincs catch-all, `INBOX_MODE=plus` mellett plusz-címzés is működik.
- **PHP 8.2+** a webcímhez, `pdo_pgsql`, `mbstring`, `intl`, `gd`, `zip`,
  `fileinfo` kiterjesztésekkel.

### Külső szolgáltatások

- **OpenRouter** — a kiolvasás. `OPENROUTER_API_KEY` és `OPENROUTER_MODEL`.
- **Stripe** — előfizetés. A csomagok darabkerete a `config/szamlafolyo.php`-ban
  van az ár-azonosítókhoz kötve; a webhook címe `/stripe/webhook`.
