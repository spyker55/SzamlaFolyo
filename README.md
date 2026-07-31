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
- `src/lib/export/` — könyvelői export. A `csv.ts` pontosvesszős, BOM-os,
  magyar tizedesvesszős CSV-t ír, mert a magyar Excel ezt olvassa számként; a
  szöveges cellákat formula-injekció ellen védi (`=`, `+`, `-`, `@` elé
  aposztróf), a számoszlopokat viszont nem, különben a sztornó mínusza
  szövegbe fordulna. Az **összesítés csak a számviteli bizonylatokra** megy
  (számla, előlegszámla, helyesbítő, sztornó, nyugta): a díjbekérő és a rá
  kiállított számla együtt kétszer vinné be ugyanazt a költséget. A `zip.ts`
  saját, tömörítés nélküli (STORE) ZIP-író — a PDF már tömörített, ezért a
  deflate csak CPU-t égetne, így pedig nincs új függőség.
- `src/lib/iktatas/ugy-suggest.ts` — determinisztikus ügy-javaslat (nem modellhívás): azonos partner **és** azonos összeg, és a javaslat mindig megmondja, miért ajánlja. Semmi nincs előre kiválasztva.
- `app.resolve_partner()` — partner-feloldás adószám, majd normalizált név alapján. A normalizálás kisbetűsít, ékezetet és írásjelet távolít el, de a **cégformát nem** vágja le: a `Kft.` és a `Bt.` két külön jogi személy. Adószám nélküli élő partnereknél részleges unique index garantálja, hogy egy névhez egy sor tartozzon.

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
- `tests/export-csv.test.ts` — hónaphatárok (szökőév is), a magyar
  számformátum, a formula-injekció elleni védelem, és hogy a díjbekérő nem
  duplázza meg a könyvelendő összeget.
- `tests/export-zip.test.ts` — az archívumot a **rendszer `unzip`-je** nyitja
  meg és ellenőrzi (`unzip -t`), nem a saját olvasónk; az ékezetes fájlnév és
  a bájtra pontos tartalom is visszaolvasva.
- `tests/email-inbound.test.ts` — webhook-aláírás (hamisítás, visszajátszás,
  hiányzó fejléc), címzett-feloldás és payload-olvasás. A payload-teszt egy
  **valódi Resend-kézbesítés** szó szerinti másolatán fut, nem kitalált alakon.

A másik két teszt a Supabase projekt **publikus API-ján** fut (anon kulcs +
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
- `tests/partner-dedup.test.ts` — adószám nélküli szállító nem kap minden
  iktatásnál új partner-sort (kis/nagybetű, ékezet, írásjel egyezik); a `Kft.`
  és a `Bt.` viszont **nem** olvad össze; a később megjelenő adószám a meglévő
  partnerre íródik, nem hoz létre másodikat.
- `tests/rls-isolation.test.ts` — másik cég usere semmilyen úton nem látja, nem
  módosítja és nem iktatja az első cég iratait; a `p_ugy_id`-vel sem tudja a saját
  iratát idegen ügy alá fűzni; anon semmit nem lát; fizikai törlés senkinek.

A két teszt-user (`teszt.a@szamlafolyo-test.hu`, `teszt.b@szamlafolyo-test.hu`)
a projektben seedelve van megerősített e-maillel. A jelszavuk **nincs a
repóban** — állítsd be a `TEST_USER_PASSWORD`-öt a `.env.test`-ben.

Ha nincs `.env.test`, ez a két suite **kihagyódik** (a vitest kiírja, hány
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
- A könyvelői export (`/export`) csak **iktatott** iratot ad ki; az
  érvénytelenítettek is benne vannak, jelölve, de az összegen nem mozdítanak.
  A ZIP-be az eredeti fájlok kerülnek, iktatószámmal kezdődő néven, és benne
  utazik ugyanaz a CSV is — az archívum önmagában értelmezhető. Az egész
  archívum memóriában áll össze, ezért 100 MB-nál és 2000 iratnál a route
  inkább elutasítja a kérést, mint hogy a függvény kifogyjon a memóriából.
- A gépi kinyerés értékei (`extraction.parsed_fields`) soha nem íródnak felül;
  a kézi javítás külön sor a `field_correction` táblában.
