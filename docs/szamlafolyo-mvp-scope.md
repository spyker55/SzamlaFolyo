# Számlafolyó — MVP scope, adatmodell, képernyők

**Verzió:** v0.2 · 2026. július
*(v0.1-hez képest: iktatókönyv és `ugy` entitás bekerült, a `supplier` átalakult `partner`-ré, a `supersedes` beolvadt az ügy-logikába.)*

**Cél:** a legszűkebb működő termék, amivel 5–10 fizető pilotügyfelet ki lehet szolgálni.

---

## 1. Az MVP határa

**Benne:** irat bevitel (e-mail / fotó / PDF) · AI adatkinyerés emberi jóváhagyással · iktatás iktatószámmal · ügyre fűzés · határidő- és előadó-követés · partnertörzs · NAV Online Számla egyeztetés · fizetési naptár · banki utalási fájl · havi export könyvelőnek · meglévő iktató-Excel importja.

**Nincs benne:** jóváhagyási workflow · költséghely-dimenziók · tételsor-szintű rögzítés · főkönyvi kontírozás · banki API · ERP-csatlakozó · natív mobilapp · angol nyelv · munkaidő/flotta/HR · közigazgatási tanúsítás · selejtezési eljárás (csak jelzés).

---

## 2. Adatmodell

Konvenciók:

- Azonosító `UUID`. Minden üzleti tábla hordoz `company_id`-t, row-level security véd.
- Pénzösszeg `NUMERIC(18,4)`, soha nem lebegőpontos; összeg és pénznem együtt.
- `created_at`, `updated_at` (`TIMESTAMPTZ`), a fontosakon `created_by`.
- Soft delete (`deleted_at`). **Iktatott iratot fizikailag törölni nem szabad** — érvénytelenítve is a könyvben marad.

### 2.1 Törzs

**`company`**
`id · name · tax_number · address · default_currency · nav_technical_user (titkosítva) · accounting_software · ingest_email_local_part · plan`

**`app_user`** · **`company_member`**
`company_member: id · company_id · user_id · role (owner | admin | eloado | viewer) · accepted_at`
→ Az `eloado` szerep az iktatókönyv „Előadó" oszlopa: hozzá lehet ügyet rendelni.

**`partner`** *(v0.1-ben `supplier` volt)*
`id · company_id · name · tax_number · eu_tax_number · country · is_supplier · is_customer · default_payment_term_days · note`
→ Átnevezve, mert a beküldő nem mindig szállító (hatóság, ügyfél, bank). Index: `(company_id, tax_number)` — a név megbízhatatlan azonosító.

**`partner_bank_account`**
`id · partner_id · iban / account_number · bank_name · currency · first_seen_at · is_primary`
→ Új számlaszám megjelenése **gyanús esemény** (számlacsalás), a UI jelezze.

**`irattari_tetel`** — irattári terv
`id · company_id · jel · megnevezes · orzesi_ido_ev · nem_selejtezheto (bool)`
→ Az őrzési időből automatikusan adódik, mi helyezhető irattárba és minek járt le a megőrzése. Olcsó funkció, jól mutat.

### 2.2 Iktatókönyv és ügy

**`iktatokonyv`**
`id · company_id · ev · nev · prefix · next_foszam · opened_at · closed_at`
→ Évente nyílik és zárul, a főszám január 1-jén 1-ről indul.

**`ugy`** — az ügy, azaz az összetartozó iratok kötege
`id · company_id · iktatokonyv_id · foszam · ev · targy · partner_id · eloado_user_id · irattari_tetel_id · status · hatarido · parent_ugy_id · opened_at · closed_at · irattarba_helyezve_at`

`status`: `folyamatban | felfuggesztve | lezart | irattarazott`

**`document`** — egy konkrét irat az ügyön belül
`id · company_id · ugy_id · alszam · iktatoszam · direction · doc_kind · processing_status · payment_status · export_status · partner_id · targy · irat_szama · erkezett_at · issue_date · fulfillment_date · due_date · melleklet_db · kezelesi_feljegyzes · currency · net_amount · vat_amount · gross_amount · huf_gross_amount · exchange_rate · payment_method · partner_bank_account_id · payment_reference · source · sha256_group`

**A típus két ortogonális mező, nem egy szabad szöveg:**

| Mező | Értékek |
|---|---|
| `direction` | `bejovo \| kimeno \| belso` |
| `doc_kind` | `level \| szamla \| dijbekero \| szerzodes \| teljesites \| nyilatkozat \| egyeb` |

> Ez a felbontás szünteti meg a „bejövő számla" / „bjövő számla" típusú törzsadat-romlást: kötött szótár, két dimenzió, nincs szabadon gépelt kategória. Bővíteni csak `doc_kind`-ot kelljen, ne 2×N kombinációt.

**Három ortogonális állapot** — tudatos döntés, ne olvadjon egy mezőbe:

| Mező | Értékek |
|---|---|
| `processing_status` | `received → extracting → needs_review → iktatva` \| `extraction_failed` \| `ervenytelenitve` \| `duplicate` |
| `payment_status` | `not_payable \| unpaid \| scheduled \| paid \| partially_paid` |
| `export_status` | `not_exported \| exported` |

**A díjbekérő–számla probléma az ügyön belül oldódik meg.** A v0.1-beli `supersedes_document_id` megmarad, de már ügyön belüli hivatkozás: a végszámla felülírja a díjbekérőt, és a fizetési naptár csak azt számolja, amire nem mutat `supersedes`. Enélkül a kötelezettség duplán jelenik meg, és a fő képernyő hazudik.

### 2.3 Iktatószám képzése

Formátum: **`{prefix}/{foszam}-{alszam}/{ev}`** — pl. `IKT/128-3/2026`.

- Új ügy → `iktatokonyv.next_foszam` kiosztása, `alszam = 1`.
- Meglévő ügyhöz érkező irat → azonos főszám, következő alszám.
- Az `iktatoszam` denormalizált megjelenítési string, de **kiosztás után soha nem változik**, és nem használható újra.

**Kiosztás technikailag:** a főszámnak **hézagmentesnek** kell lennie — egy iktatókönyvben a hiányzó sorszám gyanús. A Postgres `SEQUENCE` visszagörgetéskor hézagot hagy, ezért **ne azt használd**: a `iktatokonyv.next_foszam` sort `SELECT ... FOR UPDATE`-tel zárold, és ugyanabban a tranzakcióban növeld, amelyikben az ügy létrejön. Kis forgalomnál ez tökéletesen elég, és garantáltan folytonos.

### 2.4 Kinyerés és tanulás

**`document_file`**
`id · document_id · storage_path · original_filename · mime_type · page_count · sha256 · uploaded_at`

**`extraction`**
`id · document_id · model_name · model_version · prompt_version · raw_output (JSONB) · field_confidence (JSONB) · started_at · finished_at · cost · error`
→ **Soha ne írd felül a kinyert értéket a javítottal.** A gépi és az emberi érték külön él: így mérhető a pontosság és visszakövethető, ki mit írt át.

**`field_correction`**
`id · document_id · extraction_id · field_name · machine_value · human_value · corrected_by · corrected_at`
→ Ez az adat idővel többet ér, mint maga a szoftver.

**`ugy_suggestion`** — melyik meglévő ügyhöz javasolja a rendszer az iratot
`id · document_id · ugy_id · score · reason · accepted (bool) · decided_by · decided_at`
→ Az elfogadás/elutasítás visszamérhető: ez a funkció minősége dönti el, hogy az ügyek valóban összeállnak-e.

### 2.5 NAV, fizetés, export

**`nav_invoice`** `id · company_id · nav_invoice_number · supplier_tax_number · supplier_name · issue_date · fulfillment_date · currency · net/vat/gross · nav_raw_xml · fetched_at`

**`nav_match`** `id · document_id · nav_invoice_id · match_type (auto | manual) · confidence · matched_at`
→ Párosítási kulcs sorrendben: (adószám + bizonylatszám) → (adószám + bruttó + kelt) → kézi.

**`document_vat_line`** `id · document_id · vat_rate · net_amount · vat_amount · vat_code`
→ Tételsort nem rögzítünk, ÁFA-bontást igen — enélkül a könyvelői export használhatatlan.

**`payment_batch`** `id · company_id · name · bank · value_date · total_amount · currency · file_path · status`
**`payment_batch_item`** `id · payment_batch_id · document_id · amount · partner_bank_account_id`
**`export_run`** `id · company_id · period_start · period_end · target_software · file_path · document_count`
**`audit_log`** `id · company_id · user_id · entity_type · entity_id · action · before/after (JSONB) · ip · created_at`

---

## 3. Képernyők

### MVP-ben kötelező

| # | Képernyő | Mit csinál |
|---|---|---|
| 1 | **Bejelentkezés / cégválasztó** | Egy user több cégnél. |
| 2 | **Onboarding varázsló** | Cégadat · **iktató-Excel importja** · irattári terv · előadók meghívása · NAV technikai felhasználó · bank és könyvelőprogram · beérkeztető e-mail cím tesztje. |
| 3 | **Beérkező (Inbox)** | Feldolgozás alatt / ellenőrzésre váró iratok, sürgős lejárat elöl. A napi belépési pont. |
| 4 | **Irat-ellenőrző** ⭐ | Osztott nézet: balra az eredeti kép/PDF, jobbra a kinyert mezők; kattintásra a mező kiemelődik a képen. Alacsony konfidencia sárgán. Itt dől el az ügy-hozzárendelés is (javaslat + „új ügy"). Billentyűzettel végigvihető, Enter = iktatás és ugrás a következőre. **Ez a képernyő dönti el, hogy a termék gyors-e.** |
| 5 | **Iktatókönyv** | A klasszikus tábla, ugyanazokkal az oszlopokkal, amit ma Excelben vezetnek — szűrhető, kereshető, exportálható. Ez adja az „á, ezt értem" élményt az első percben. |
| 6 | **Ügy nézet** | Egy ügy összes irata időrendben, előadó, határidő, státusz, kezelési feljegyzések. |
| 7 | **Határidők / Saját feladataim** ⭐ | Mi jár le, kinél van, mi késett. Előadónkénti és cégszintű nézet. |
| 8 | **Fizetési naptár** ⭐ | Lejáró kötelezettségek, 7/14/30 napos összesítés, késettek pirosan. |
| 9 | **Utalási csomag** | Kijelölés → összesítő → banki importfájl → „megjelölés kifizetettként". |
| 10 | **Partnerek** | Törzs, számlaszámok, fizetési határidő; új bankszámlaszám figyelmeztetéssel. |
| 11 | **NAV egyeztetés** | Két lista: *NAV-ban van, nálam nincs* / *nálam van, NAV-ban nincs*. Kézi párosítás. |
| 12 | **Havi zárás** | Időszak → könyvelőprogram-formátumú export + PDF-csomag → letöltés vagy e-mail. |
| 13 | **Beállítások** | Cég, felhasználók és szerepek, irattári terv, bank, könyvelőprogram, adatkezelés. |

### v1.1-re halasztható

Irattár és megőrzés-lejárat nézet · riportok, előadói terhelés · e-mail értesítés lejáró tételekről *(gyanúsan olcsó és gyanúsan hasznos — lehet, hogy előre kell hozni)* · ismétlődő számlák felismerése · audit napló felület · teljes szöveges keresés a dokumentumtartalomban.

---

## 4. Feldolgozási folyamat

```
bevitel (email | fotó | upload)
   → fájl mentés + sha256 duplikátum-ellenőrzés
   → háttér job: AI kinyerés
   → partner azonosítás adószám alapján
   → direction + doc_kind meghatározás
   → ügy-javaslat (meglévő ügyhöz vagy új ügy)
   → konfidencia küszöb ─── magas ──→ auto-iktatás (cégenként kapcsolható)
                        └── alacsony ─→ needs_review → ember
   → iktatás: iktatószám kiosztása (tranzakcióban)
   → megjelenik az iktatókönyvben, a határidőkben és — ha számla — a fizetési naptárban
```

**Építési megjegyzések:**

- A kinyerés **aszinkron job**, ne HTTP kérésen belül: sorbaállítás, újrapróbálkozás, hibakezelés kell.
- Az e-mail beérkeztetés **idempotens** (message-id alapú deduplikáció) — az újraküldött levél ne csináljon második iratot.
- Egy levélben több melléklet, egy PDF-ben több irat is jöhet. MVP-ben elég, ha *észreveszi* és kézi szétvágást kér.
- A modell nyers válaszát mindig mentsd. Prompt- vagy modellcserénél ez az egyetlen mód visszamérni, hogy jobb lett-e.
- **Az iktatószám kiosztása és az ügy létrehozása egy tranzakció.** Ha ez szétesik, hézagos vagy ütköző számok keletkeznek, és onnantól a könyv nem hiteles.

---

## 5. Nyitott kérdések, mielőtt kódolsz

1. **Egy iktatókönyv vagy több?** Kis cégnél egy elég; egyes szervezetek bejövő/kimenő külön könyvet vezetnek. A modell bírja — döntsd el, mit engedsz a UI-ban, mert a főszám-kiosztás ettől függ.
2. **NAV technikai felhasználó** — az ügyfélnek magának kell létrehoznia; valós onboarding-súrlódás, képes útmutató nélkül itt hullanak el a próbálók.
3. **Banki importfájl formátumok** — bankonként eltérők és verziózottak. Szerezz éles mintafájlt, mielőtt négy bankot ígérsz. Indulj eggyel.
4. **Megőrzés és archiválás** — jogi megerősítés kell; befolyásolja a storage-architektúrát, utólag drága.
5. **Auto-iktatás küszöbe** — egy rossz adat többet árt, mint tíz megspórolt kattintás. A pilotoknál kapcsold ki, és a `field_correction` adatból számold ki, hol lenne biztonságos.
6. **Hol fut az AI-feldolgozás** — az adatkezelési tájékoztató és az ügyfél első kérdése ezen múlik. Döntsd el a pilot előtt.
