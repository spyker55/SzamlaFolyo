# Számlafolyó — 1. mérföldkő: megvalósítási terv

**Állapot:** terv, kód még nincs. A nyitott döntéseket a 6. szakasz sorolja fel —
mindegyiknél a megjelölt ajánlással haladok tovább, amíg más döntés nem születik.

---

## 1. Mappastruktúra

```
SzamlaFolyo/
├── app/
│   ├── (auth)/
│   │   ├── bejelentkezes/page.tsx
│   │   └── regisztracio/page.tsx          # user + cég létrehozása egyben
│   ├── (app)/                             # bejelentkezett réteg, közös layout
│   │   ├── inbox/page.tsx                 # feltöltés (drag & drop) + feldolgozás alatti iratok
│   │   ├── ellenorzes/[documentId]/page.tsx   # ⭐ osztott nézet, billentyűzet-vezérelt
│   │   └── iktatokonyv/page.tsx           # Excel-oszlopos tábla, szűrés, keresés
│   └── api/
│       ├── jobs/extract/route.ts          # worker: egy irat AI-kinyerése
│       └── cron/sweep/route.ts            # percenkénti söprés: elakadt jobok újraindítása
├── lib/
│   ├── supabase/                          # server.ts (SSR client), client.ts, admin.ts (service role)
│   ├── extraction/
│   │   ├── prompt.ts                      # PROMPT_VERSION konstanssal, verziózva
│   │   ├── schema.ts                      # zod séma a strukturált kimenetre
│   │   ├── run.ts                         # Anthropic-hívás, extraction sor írása
│   │   └── confidence.ts                  # determinisztikus validátorok (l. 4.3)
│   ├── iktatas/actions.ts                 # server action: iktat RPC hívása + navigáció
│   └── upload/actions.ts                  # feltöltés, sha256, duplikátum-ellenőrzés
├── supabase/
│   ├── config.toml
│   └── migrations/                        # l. 2. szakasz
├── tests/
│   ├── iktatas-concurrency.test.ts        # 50 párhuzamos iktatás, hézag/ütközés-ellenőrzés
│   └── rls-isolation.test.ts              # két cég, keresztbe olvasás tiltása
└── vercel.json                            # cron definíció
```

Elv: a domain-logika (`lib/extraction`, `lib/iktatas`) nem importál React-ot,
így a tesztek UI nélkül futtatják.

## 2. Migrációk sorrendje

Minden séma-változás `supabase/migrations/*.sql` fájl, a Supabase CLI kezeli.
**Az RLS policy abban a migrációban él, amelyik a tábláját létrehozza** — tábla
nem létezhet egyetlen commitban sem policy nélkül. Minden táblán a létrehozó
migráció kapcsolja be a `FORCE ROW LEVEL SECURITY`-t is.

1. `0001_helpers.sql` — `app` séma; `app.touch_updated_at()` trigger-függvény;
   `app.user_company_ids()` (`SECURITY DEFINER`, `STABLE`): a bejelentkezett user
   cégeit adja vissza — ezt hivatkozza minden policy, így a `company_member`-en
   nincs rekurzív RLS és a tervező cache-elheti.
2. `0002_company_membership.sql` — `company`, `app_user` (az `auth.users` tükörprofilja,
   triggerrel), `company_member` (`role: owner|admin|eloado|viewer`) + RLS.
3. `0003_partner.sql` — `partner` + RLS, index `(company_id, tax_number)`.
4. `0004_iktatokonyv_ugy.sql` — `iktatokonyv` (`UNIQUE (company_id, ev)`),
   `ugy` (`UNIQUE (iktatokonyv_id, foszam)`) + RLS.
5. `0005_document.sql` — `document` (`UNIQUE (ugy_id, alszam)`, részleges unique
   a cégen belüli `iktatoszam`-ra), `document_file` (`UNIQUE (company_id, sha256)`
   helyett: unique index a duplikátum-jelzéshez **nem** kell, a keresés indexelt) + RLS.
   `DELETE`-et a policy senkinek nem enged — csak `deleted_at` / érvénytelenítés.
6. `0006_extraction.sql` — `extraction`, `field_correction` + RLS.
7. `0007_iktat_fn.sql` — `iktat_document(p_document_id)` Postgres függvény (l. 4.4).
8. `0008_storage.sql` — privát `iratok` bucket; storage policy: útvonal
   `{company_id}/{document_file_id}/{fájlnév}`, olvasás csak tag cégre.

Enumok Postgres `ENUM` típusként (`direction`, `doc_kind`, `processing_status` stb.)
— ez maga a „kötött szótár" a törzsadat-romlás ellen.

Pénz: `NUMERIC(18,4)` + `currency CHAR(3)`, CHECK: összeg és pénznem csak együtt lehet kitöltve.

## 3. Fejlesztési és teszt-környezet

- Helyben: `supabase start` (Docker elérhető a gépen) — a tesztek a helyi
  Postgres ellen futnak, nem az éles projekt ellen.
- Éles: a meglévő `szamlafolyo` Supabase-projekt (eu-central-1, jelenleg INACTIVE —
  visszaállítandó, amikor deployolunk), `supabase db push` a migrációkkal.
- A konkurencia-teszt (50 párhuzamos `iktat_document` hívás) és az RLS-teszt
  vitest-tel fut, két külön teszt-userrel és két céggel a helyi stacken.

## 4. A kritikus részek megvalósítása

### 4.1 Feltöltés és duplikátum

Kliens számol sha256-ot (Web Crypto), a szerver **újraszámolja** (a kliensnek nem hiszünk).
Storage-ba töltés után `document` (`processing_status='received'`) + `document_file` sor.
Ha a cégnél már van élő `document_file` ugyanazzal a hash-sel: a blobot nem töltjük fel
újra, a `document` sor `processing_status='duplicate'`-tal jön létre és az eredeti iratra
hivatkozik (`sha256_group`) — így az Inboxban látszik és auditálható, de iktatószámot
soha nem kaphat. *(→ 6. szakasz, 2. döntés)*

### 4.2 Aszinkron kinyerés

**Séma: azonnali kick + cron-söprés, minden Next.js-ben.**

- Feltöltés után a szerver `after()`-rel (Next 15) meghívja a `/api/jobs/extract`
  route-ot a document id-vel — ez adja a 30 mp-en belüli élményt.
- A worker **claim-mel** indul: `UPDATE document SET processing_status='extracting',
  extraction_attempts = extraction_attempts + 1 WHERE id=$1 AND processing_status IN
  ('received') RETURNING id` — ha nem kap sort vissza, más már dolgozik rajta. Idempotens.
- `vercel.json` cron percenként hívja a `/api/cron/sweep`-et (CRON_SECRET-tel védve):
  felveszi a `received` állapotban ragadt és a timeoutolt `extracting` iratokat;
  3 kísérlet után `extraction_failed`.
- A worker a **service role** klienssel ír (RLS-t megkerüli), de minden lekérdezése
  explicit `company_id`-szűrt.

Kinyerés: Anthropic API, a PDF **natívan** megy a modellnek (document block),
kép image blockként. Strukturált kimenet zod-dal validálva. A nyers válasz —
validálás előtt — az `extraction.raw_output`-ba kerül `prompt_version` +
`model_name` + `model_version` mellé, akkor is, ha a parse elhasal.

### 4.3 Konfidencia

Két forrás, külön kulcsok alatt a `field_confidence` JSONB-ben:

- a modell önbevallott mezőnkénti 0–1 értéke,
- determinisztikus validátorok: magyar adószám formátum + CDV-ellenőrzőszámjegy,
  `net + vat = gross` egyezés, dátumok parse-olhatósága és sorrendje
  (`issue_date ≤ due_date`), pénznem ISO-4217.

Ahol validátor van, az **felülbírálja** a modellt (bukott validátor ⇒ alacsony
konfidencia, akármit mond a modell). Az ellenőrző képernyő a kombinált értékből
emel ki 0,85 alatt. A két jel külön tárolása miatt később visszamérhető,
melyik kalibrált jobban.

### 4.4 Iktatás — hézagmentes főszám

Egyetlen Postgres függvény (`SECURITY INVOKER`, tehát az RLS a hívóra érvényes),
egy tranzakcióban:

1. `SELECT ... FOR UPDATE` az adott cég + év `iktatokonyv` sorára
   (ha nincs, `INSERT ... ON CONFLICT DO NOTHING` + újra lock);
2. `foszam := next_foszam`, `next_foszam := next_foszam + 1`;
3. új `ugy` sor (`alszam`-logika e mérföldkőben mindig új ügy, `alszam = 1`),
   `targy`/`partner` átvéve az iratról;
4. `document` frissítése: `ugy_id`, `alszam`, `iktatoszam = {prefix}/{foszam}-{alszam}/{ev}`,
   `processing_status = 'iktatva'` — **csak** `needs_review` állapotból;
5. a felhasználó által átírt mezők mentése: eredeti kinyert érték érintetlen,
   minden eltérés egy-egy `field_correction` sor, és az irat a **javított** értékeket kapja.

Ha bármi elhasal, a rollback a `next_foszam`-ot is visszagörgeti — ezért nincs hézag.
A sor-lock sorosítja a párhuzamos iktatást; kis forgalomnál ez elég.
Erre megy rá a kötelező konkurencia-teszt.

### 4.5 Ellenőrző képernyő

Osztott nézet: balra PDF (natív `<embed>`/pdf.js) vagy kép, jobbra a mezők.
Fókusz-sorrend rögzített, `Tab` mezők közt, `Enter` = iktatás + következő
`needs_review` irat betöltése, `Esc` = vissza az Inboxba. Alacsony konfidencia
sárga kerettel + ikonnal (ne csak szín hordozza). Mentés Enterkor egyben —
nincs mezőnkénti autosave, mert az iktatás atomi.

## 5. RLS-elvek

- Minden üzleti táblán `company_id`, policy: `company_id IN (SELECT app.user_company_ids())`,
  `SELECT/INSERT/UPDATE`-re külön, `DELETE` sehol nem engedett.
- `app_user`-t a saját sorára látja a user; `company` sort csak tag.
- Storage-objektumok útvonala `{company_id}/...`, a policy az útvonal első
  szegmensét ellenőrzi tagság ellen.
- A service role csak a workerben és a cron-ban él, kliensre soha nem kerül.

## 6. Ellentmondások és döntések a specifikációban

A ✅ jelű pontoknál döntöttem és e szerint építek; kifogás esetén szólj, olcsón visszafordítható.

1. ✅ **Egy user egy cég vs. cégválasztó.** A mérföldkő „egy user egy céghez"-t mond,
   a scope-dokumentum 1. képernyője cégválasztót („egy user több cégnél"), miközben a
   tiltólistán ott a „többcéges felhasználó". Döntés: a **séma** many-to-many
   (`company_member`), a **UI** e mérföldkőben egy céget kezel, cégválasztó nincs.
   Így később nem kell migrálni.
2. ✅ **Duplikátum.** A mérföldkő szerint duplikátumnál „ne hozz létre új iratot", de a
   séma `processing_status`-ában létezik `duplicate` érték — a kettő ellentmond.
   Döntés: létrejön a `document` sor `duplicate` státusszal (blob-újratöltés nélkül,
   az eredetire hivatkozva), mert auditálható és a státusz-enum erre utal.
   **Ha szó szerint a mérföldkő-szöveg kell (semmi ne jöjjön létre), ezt jelezd.**
3. ✅ **Iktatókönyvek száma** (a scope-dokumentum 1. nyitott kérdése). Döntés:
   cégenként-évente pontosan egy, `IKT` prefixszel, az első iktatáskor automatikusan
   létrejön. A séma többet is bír, UI-váltás nélkül bővíthető később.
4. ✅ **Konfidencia forrása.** A modell önbevallott értéke gyengén kalibrált, ezért
   determinisztikus validátorokkal kombinálom (4.3). Több munka, de a doksi maga
   mondja: egy rossz adat többet árt tíz kattintásnál.
5. ⚠️ **Hol fut az AI** (6. nyitott kérdés a scope-ban): a kinyerést az Anthropic API
   végzi — a feldolgozás így az EU-n kívül történhet, miközben a termékdefiníció
   EU-s tárolást ígér. A *tárolás* (Supabase eu-central-1) EU-s marad, de az
   adatkezelési tájékoztatónak ki kell mondania az AI-feldolgozás helyét.
   **Pilot előtt üzleti döntés kell; a kódot nem blokkolja.**
6. ℹ️ **`fulfillment_date` és ÁFA-bontás.** A `document` sémában van teljesítés kelte
   és a scope-ban `document_vat_line`, de a mérföldkő kinyerési mezőlistájában egyik
   sincs. A mérföldkő-listát követem; az oszlop a sémában létrejön, a `document_vat_line`
   tábla nem (feljegyezve későbbre).
7. ℹ️ **Worker-környezet:** Next.js route + `after()` + percenkénti Vercel cron.
   A perces cron **Vercel Pro** csomagot feltételez — ha csak Hobby van, a söprés
   ritkább (napi) és a kick-elés hibája esetén az irat tovább áll sorban.
8. ℹ️ **`erkezett_at`**: az iktatókönyv „Érkezett" oszlopa naptári nap, ezért `DATE`
   (nem `TIMESTAMPTZ`); a technikai beérkezés a `created_at`-ból mindig megvan.

## Későbbre feljegyezve (e mérföldkőben tudatosan kihagyva)

`document_vat_line` (könyvelői exporthoz) · `ugy_suggestion` · `partner_bank_account`
és számlaszám-figyelmeztetés · `irattari_tetel` · `nav_*` táblák · `payment_batch` ·
`audit_log` · e-mail beérkeztetés (idempotencia message-id alapján) · több irat egy
PDF-ben (észlelés + kézi szétvágás) · auto-iktatás küszöb.
