-- Irattári terv: mennyi ideig kell megőrizni, és mikortól nem kell.
--
-- `ugy.irattari_jel` has been free text since the first migration. It prints
-- on the iktatókönyv and nothing reads it, which means the register records a
-- classification that decides nothing. The question it is supposed to answer —
-- "ezt az ügyet meddig kell megtartani?" — has no answer in the system today,
-- and for a Hungarian company that is not a filing preference, it is a legal
-- obligation with a number attached to it.
--
-- Four decisions.
--
-- One: the tétel belongs to the ügy, not to the irat. That is how a Hungarian
-- iktatórendszer works — the ügy is the unit that goes into the archive, and
-- every irat filed under it shares its fate. A számla and the díjbekérő it
-- answers cannot have different retention periods; they are one case.
--
-- Two: the retention clock starts when the ügy is **closed**, not when its
-- iratok are dated. The Számviteli törvény counts from the business year of
-- the bizonylat, and for an ügy opened and closed inside one year those are
-- the same. Where they differ, the closing is always the later date, so
-- counting from it never disposes of anything early. Erring in that direction
-- is the whole point.
--
-- Three: **the app never deletes anything.** Selejtezés here means the system
-- tells you what has come due; it does not act. That is not timidity — it is
-- the same rule the register has carried from the start ("iktatott iratot
-- fizikailag törölni tilos"), and a retention schedule that quietly destroys
-- records would be the one feature capable of losing what everything else in
-- this codebase exists to keep.
--
-- Four: the seeded terv is a **starting point, not advice**. Every row carries
-- the law it comes from so that whoever reviews it can check it, and every row
-- is editable. The periods below are legal minimums; a company may keep things
-- longer, and some should.

create table if not exists public.irattari_tetel (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  -- The mark that ends up printed next to the iktatószám. Immutable once
  -- created (see the guard below): it is half of what an irattári jel means.
  tetelszam text not null,
  nev text not null,
  -- Null is not "unknown" — it is **nem selejtezhető**, the strongest value
  -- this column can hold. A number here is a permission to destroy after it
  -- runs out; the absence of one is a refusal that never runs out.
  orzesi_ido_ev integer,
  -- Which law says so. Without this the terv is a list of opinions.
  jogszabaly text,
  megjegyzes text,
  sorrend integer not null default 100,
  -- Tételek are retired, not deleted: ügyek point at them, and an ügy whose
  -- classification vanished could not be classified at all.
  deleted_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (company_id, tetelszam),
  constraint irattari_tetel_orzesi_ido_sane
    check (orzesi_ido_ev is null or (orzesi_ido_ev >= 1 and orzesi_ido_ev <= 100))
);

alter table public.irattari_tetel enable row level security;
alter table public.irattari_tetel force row level security;

drop trigger if exists irattari_tetel_touch_updated_at on public.irattari_tetel;
create trigger irattari_tetel_touch_updated_at
  before update on public.irattari_tetel
  for each row execute function app.touch_updated_at();

create index if not exists irattari_tetel_company_idx
  on public.irattari_tetel (company_id, sorrend, tetelszam);

drop policy if exists irattari_tetel_select on public.irattari_tetel;
create policy irattari_tetel_select on public.irattari_tetel
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

-- Writing the terv is the same kind of act as érvénytelenítés or a partner
-- merge: an előadó files iratok, an owner or admin decides how long the
-- company keeps them. Shortening a retention period is, in the end, a
-- decision to destroy records.
drop policy if exists irattari_tetel_insert on public.irattari_tetel;
create policy irattari_tetel_insert on public.irattari_tetel
  for insert to authenticated
  with check (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

drop policy if exists irattari_tetel_update on public.irattari_tetel;
create policy irattari_tetel_update on public.irattari_tetel
  for update to authenticated
  using (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  )
  with check (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

create or replace function app.protect_irattari_tetel()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.id is distinct from old.id
     or new.company_id is distinct from old.company_id
     or new.created_at is distinct from old.created_at then
    raise exception 'irattari tetel identity is immutable (id, company)';
  end if;

  -- The tételszám is printed onto the ügy as its irattári jel at the moment of
  -- classification, and that print is a historical record. If the number could
  -- move, an old ügy's jel would point at a tétel that is no longer the one it
  -- was filed under. Retire the row and make a new one instead.
  if new.tetelszam is distinct from old.tetelszam then
    raise exception 'the tetelszam of an irattari tetel cannot be changed; retire it and create a new one';
  end if;

  return new;
end;
$$;

drop trigger if exists irattari_tetel_protect on public.irattari_tetel;
create trigger irattari_tetel_protect
  before update on public.irattari_tetel
  for each row execute function app.protect_irattari_tetel();

-- The ügy's classification ---------------------------------------------

alter table public.ugy
  add column if not exists irattari_tetel_id uuid references public.irattari_tetel (id);

create index if not exists ugy_irattari_tetel_idx
  on public.ugy (irattari_tetel_id) where irattari_tetel_id is not null;

-- protect_ugy() freezes an archived ügy completely, and that has to give way
-- here for the same reason it gave way for a partner merge. The irattári jel
-- is not a statement about what happened in the ügy — it is how the archive
-- is organised, and reorganising the archive is an ordinary archival act. The
-- alternative is that a misclassified ügy can never be corrected once it is
-- put away, and a misclassification is precisely what causes a record to be
-- destroyed too early.
create or replace function app.protect_ugy()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  v_allowed constant text[] := array[
    'folyamatban->felfuggesztve',
    'folyamatban->lezart',
    'felfuggesztve->folyamatban',
    'felfuggesztve->lezart',
    'lezart->folyamatban',
    'lezart->felfuggesztve',
    'lezart->irattarazott',
    'irattarazott->lezart'
  ];
  v_frozen public.ugy%rowtype;
begin
  if new.id is distinct from old.id
     or new.company_id is distinct from old.company_id
     or new.iktatokonyv_id is distinct from old.iktatokonyv_id
     or new.foszam is distinct from old.foszam
     or new.ev is distinct from old.ev
     or new.opened_at is distinct from old.opened_at
     or new.created_at is distinct from old.created_at
  then
    raise exception 'ugy identity is immutable (foszam, ev, iktatokonyv, company, opened_at)';
  end if;

  if new.status is distinct from old.status then
    if not (old.status::text || '->' || new.status::text = any (v_allowed)) then
      raise exception 'ugy status cannot go from % to %', old.status, new.status;
    end if;
  end if;

  if old.status = 'irattarazott' and new.status = 'irattarazott' then
    v_frozen := old;
    if coalesce(current_setting('app.partner_merge', true), '') = 'on' then
      v_frozen.partner_id := new.partner_id;
    end if;
    -- The archive mark, and nothing else, moves on an archived ügy.
    v_frozen.irattari_tetel_id := new.irattari_tetel_id;
    v_frozen.irattari_jel := new.irattari_jel;
    if new is distinct from v_frozen then
      raise exception 'ugy is irattarazott, take it out of the archive before editing it';
    end if;
  end if;

  if new.status = 'lezart' and old.status in ('folyamatban', 'felfuggesztve') then
    new.closed_at := now();
  elsif new.status in ('folyamatban', 'felfuggesztve') then
    new.closed_at := null;
  else
    new.closed_at := old.closed_at;
  end if;

  if new.status = 'irattarazott' and old.status <> 'irattarazott' then
    new.irattarba_helyezve_at := now();
  elsif new.status <> 'irattarazott' then
    new.irattarba_helyezve_at := null;
  else
    new.irattarba_helyezve_at := old.irattarba_helyezve_at;
  end if;

  -- A tétel from another company would be a classification the company cannot
  -- read; RLS hides the row, so the ügy would show a blank jel forever.
  if new.irattari_tetel_id is not null
     and new.irattari_tetel_id is distinct from old.irattari_tetel_id
     and not exists (
       select 1 from public.irattari_tetel t
       where t.id = new.irattari_tetel_id and t.company_id = new.company_id
     ) then
    raise exception 'irattari tetel belongs to another company';
  end if;

  return new;
end;
$$;

-- The default terv -------------------------------------------------------

-- Seeded per company so it can be edited without affecting anyone else. The
-- periods are the legal minimums; the jogszabály column names the source for
-- each one so that a bookkeeper can check the list rather than trust it.
--
-- Two of these deserve a note. 'M-1' and 'M-2' are marked nem selejtezhető
-- although the law does give them an end: Tny. 99/A. § ties munkaügyi iratok
-- to five years after the employee reaches retirement age. That date is not
-- knowable from the ügy's closing, so anything this system could compute would
-- be a guess — and a guess in the direction of destruction. Nem selejtezhető
-- is the honest value.
create or replace function app.seed_irattari_terv(p_company_id uuid)
returns void
language plpgsql
security definer
set search_path = ''
as $$
begin
  insert into public.irattari_tetel
    (company_id, tetelszam, nev, orzesi_ido_ev, jogszabaly, megjegyzes, sorrend)
  values
    (p_company_id, 'V-1', 'Létesítő okirat, cégiratok, cégbírósági levelezés', null,
     'Ptk. 3:1. §, Ctv.',
     'A társaság fennállását igazolja; a cég megszűnése után is szükséges lehet.', 10),
    (p_company_id, 'V-2', 'Taggyűlési és közgyűlési jegyzőkönyvek, határozatok', null,
     'Ptk. 3:109–3:110. §',
     'A tulajdonosi döntések bizonyítéka, elévülési idő nélkül.', 20),
    (p_company_id, 'P-1', 'Éves beszámoló, főkönyvi kivonat, leltár, értékelés', 8,
     'Szt. 169. § (1)',
     'A törvényi minimum 8 év; a beszámolót sok cég ennél tovább is megtartja.', 30),
    (p_company_id, 'P-2', 'Bejövő számlák és szállítói bizonylatok', 8,
     'Szt. 169. § (2)',
     'A könyvviteli elszámolást alátámasztó bizonylat, az üzleti év végétől számítva.', 40),
    (p_company_id, 'P-3', 'Kimenő számlák és vevői bizonylatok', 8,
     'Szt. 169. § (2)', null, 50),
    (p_company_id, 'P-4', 'Bank- és pénztárbizonylatok, kivonatok', 8,
     'Szt. 169. § (2)', null, 60),
    (p_company_id, 'P-5', 'Adóbevallások, adóhatósági levelezés', 6,
     'Art. 78. §, 202. §',
     'Az adómegállapításhoz való jog 5 év alatt évül el, az esedékesség évének utolsó napjától; ezért gyakorlatban a hatodik év végéig őrzendő. Ha a bizonylat számviteli is, a 8 év az erősebb.', 70),
    (p_company_id, 'M-1', 'Munkaszerződések, személyi anyagok, be- és kijelentések', null,
     'Tny. 99/A. §',
     'A nyugellátás megállapításához kellhet, a nyugdíjkorhatár betöltése után még öt évig. Ez az ügy lezárásából nem számolható ki, ezért nem selejtezhető.', 80),
    (p_company_id, 'M-2', 'Bérszámfejtés, jövedelemigazolás, járulékbevallás', null,
     'Tny. 99/A. §',
     'Ugyanaz az indok, mint az M-1 esetében.', 90),
    (p_company_id, 'S-1', 'Szerződések (nem munkaügyi)', 8,
     'Szt. 169. § (2), Ptk. 6:22. §',
     'A polgári jogi elévülés 5 év a teljesítéstől, de a szerződés a számviteli elszámolást is alátámasztja, ezért a hosszabb idő az irányadó.', 100),
    (p_company_id, 'H-1', 'Hatósági engedélyek, ellenőrzési jegyzőkönyvek', 10,
     null,
     'Nincs egységes szabály; az engedély érvényességéhez és az esetleges utóellenőrzéshez igazítva.', 110),
    (p_company_id, 'J-1', 'Peres és jogi iratok, végrehajtás', null,
     null,
     'Egy lezárt per iratai később is előkerülhetnek; selejtezésük egyedi döntés.', 120),
    (p_company_id, 'A-1', 'Általános ügyviteli levelezés', 5,
     null,
     'Ami nem bizonylat és nem szerződés: ajánlatkérés, tájékoztatás, egyeztetés.', 130)
  on conflict (company_id, tetelszam) do nothing;
end;
$$;

-- Every company that already exists gets the same starting terv. Without this
-- the feature would only work for companies created after today.
do $$
declare
  v_company record;
begin
  for v_company in select id from public.company loop
    perform app.seed_irattari_terv(v_company.id);
  end loop;
end $$;

-- New companies get it at creation, in the same transaction as the company and
-- the owner membership.
create or replace function public.create_company_with_owner(
  p_name text,
  p_tax_number text default null
)
returns uuid
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := (select auth.uid());
  v_company_id uuid;
begin
  if v_user_id is null then
    raise exception 'not authenticated';
  end if;

  if coalesce(trim(p_name), '') = '' then
    raise exception 'company name is required';
  end if;

  if exists (select 1 from public.company_member where user_id = v_user_id) then
    raise exception 'user already belongs to a company';
  end if;

  insert into public.company (name, tax_number)
  values (trim(p_name), nullif(trim(p_tax_number), ''))
  returning id into v_company_id;

  insert into public.company_member (company_id, user_id, role, accepted_at)
  values (v_company_id, v_user_id, 'owner', now());

  perform app.seed_irattari_terv(v_company_id);

  return v_company_id;
end;
$$;

revoke execute on function public.create_company_with_owner(text, text) from public, anon;
grant execute on function public.create_company_with_owner(text, text) to authenticated;

-- The terv is the kind of master data whose quiet edit matters: shortening a
-- period is a decision about destroying records, so it goes into the napló.
create or replace function app.audit_irattari_tetel()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_ignore constant text[] := array['updated_at'];
  v_changes jsonb;
  v_action text;
begin
  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.company_id, 'irattar.tetel_letrehozva', 'irattari_tetel', new.id,
      new.tetelszam || ' — ' || new.nev,
      '{}'::jsonb,
      jsonb_build_object('orzesi_ido_ev', new.orzesi_ido_ev)
    );
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);
  if v_changes = '{}'::jsonb then
    return null;
  end if;

  if new.deleted_at is not null and old.deleted_at is null then
    v_action := 'irattar.tetel_inaktivalva';
  elsif new.deleted_at is null and old.deleted_at is not null then
    v_action := 'irattar.tetel_visszaallitva';
  elsif new.orzesi_ido_ev is distinct from old.orzesi_ido_ev then
    v_action := 'irattar.orzesi_ido';
  else
    v_action := 'irattar.tetel_modositva';
  end if;

  perform app.audit_write(
    new.company_id, v_action, 'irattari_tetel', new.id,
    new.tetelszam || ' — ' || new.nev, v_changes, '{}'::jsonb
  );
  return null;
end;
$$;

drop trigger if exists irattari_tetel_audit on public.irattari_tetel;
create trigger irattari_tetel_audit
  after insert or update on public.irattari_tetel
  for each row execute function app.audit_irattari_tetel();
