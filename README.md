# Számlafolyó

Iktató- és ügykezelő webalkalmazás magyar kis- és középvállalkozásoknak.
A beérkező iratokat AI olvassa be és iktatja — az ember csak ellenőriz és jóváhagy.

**Stack:** Next.js (App Router, TypeScript) · Supabase (Postgres, Auth, Storage, RLS) · Anthropic API · Vercel

A termékdefiníció a `docs/` mappában, az 1. mérföldkő terve: `docs/milestone-1-terv.md`.

## Felépítés

- `supabase/migrations/` — a teljes séma és minden RLS policy, migrációnként. Séma-változtatás csak itt.
- `src/lib/extraction/` — AI-kinyerés: verziózott prompt, zod-séma, determinisztikus validátorok (adószám-ellenőrzőszámjegy, nettó+ÁFA=bruttó, dátumok).
- `src/app/api/upload` — feltöltés, szerveroldali sha256, duplikátum-jelzés.
- `src/app/api/jobs/extract` — aszinkron kinyerő worker (claim-alapú, idempotens).
- `src/app/api/cron/sweep` — percenkénti söprés: elakadt/hibás feldolgozások újraindítása (max. 3 kísérlet).
- `src/app/(app)/ellenorzes/[documentId]` — osztott ellenőrző képernyő; Enter = iktatás és ugrás a következőre.
- Az iktatószám-kiosztás egyetlen Postgres-tranzakció (`iktat_document` RPC): `SELECT ... FOR UPDATE` az `iktatokonyv.next_foszam` soron — hézagmentes, ütközésmentes főszám garantált.

## Fejlesztés

```bash
npm install
cp .env.example .env.local   # töltsd ki a kulcsokat
npm run dev
```

Szükséges környezeti változók: lásd `.env.example`. A `SUPABASE_SERVICE_ROLE_KEY`,
az `ANTHROPIC_API_KEY`, a `WORKER_SECRET` és a `CRON_SECRET` csak szerveroldalon él.

## Tesztek

```bash
cp .env.test.example .env.test   # anon kulcs
npm test
```

A tesztek a Supabase projekt **publikus API-ján** futnak (anon kulcs + jelszavas
bejelentkezés), tehát pontosan azt az utat gyakorolják, amit az alkalmazás:

- `tests/iktatas-concurrency.test.ts` — 50 párhuzamos iktatás: a főszámok halmaza
  pontosan összefüggő tartomány, se hézag, se ütközés; rollback nem hagy lyukat;
  dupla iktatás tiltva.
- `tests/rls-isolation.test.ts` — másik cég usere semmilyen úton nem látja, nem
  módosítja és nem iktatja az első cég iratait; anon semmit nem lát; fizikai
  törlés senkinek.

A két teszt-user (`teszt.a@szamlafolyo-test.hu`, `teszt.b@szamlafolyo-test.hu`)
a projektben seedelve van megerősített e-maillel.

## Üzemeltetési megjegyzések

- A worker és a cron a Vercelen fut; a percenkénti cron Pro csomagot igényel.
- Az iratok privát Storage bucketben (`iratok`), útvonal: `{company_id}/{document_id}/{fájlnév}`.
- Iktatott irat fizikailag soha nem törölhető — csak érvénytelenítés (`deleted_at` + `ervenytelenitve`).
- A gépi kinyerés értékei (`extraction.parsed_fields`) soha nem íródnak felül;
  a kézi javítás külön sor a `field_correction` táblában.
