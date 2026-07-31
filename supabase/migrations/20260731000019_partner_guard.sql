-- Partner master data gets a screen of its own, which means partner rows
-- become editable by hand for the first time. Two things have to be true
-- before that is safe.
--
-- One: a partner never changes company. partner_update is a blanket policy,
-- and a user who belongs to two companies satisfies both its USING and its
-- WITH CHECK clause, so nothing today stops a partner from being walked
-- across the tenant boundary while iratok in the company it came from still
-- point at it.
--
-- Two: merging two partners has to be one transaction, and it has to be
-- undoable. 20260730000013 said it plainly — "a wrong merge silently fuses
-- two legal entities and cannot be undone" — and that is exactly why the
-- automatic merge there was kept as narrow as it was. A screen that offers
-- merging as a button needs that sentence to stop being true, so every merge
-- records which iratok and ügyek it repointed and what the survivor learned,
-- and unmerge_partner() puts those same rows back.

alter table public.partner
  add column if not exists merged_into_partner_id uuid references public.partner (id),
  add column if not exists bank_account text,
  add column if not exists address text,
  add column if not exists email text;

-- The identity guarantee the tax number was always assumed to carry.
-- app.resolve_partner() reads it as "the identity when we have one" and then
-- takes limit 1, which is only correct if there is at most one row to take.
create unique index if not exists partner_company_tax_number_key
  on public.partner (company_id, tax_number)
  where tax_number is not null and deleted_at is null;

-- The partner screen and the merge both walk from a partner to its iratok.
create index if not exists document_partner_idx on public.document (partner_id)
  where partner_id is not null;
create index if not exists ugy_partner_idx on public.ugy (partner_id)
  where partner_id is not null;

-- 20260730000013 retired the duplicate rows it merged but recorded nothing
-- about where they went, so today a deleted partner is indistinguishable from
-- one that was merged away. Point them at the survivor the same way that
-- migration chose it: same company, same normalized name, oldest row wins.
-- These deliberately get no partner_merge row — the list of repointed iratok
-- was never captured, and inventing one would make an unmerge move rows the
-- original merge never touched.
update public.partner loser
set merged_into_partner_id = survivor.id
from public.partner survivor
where loser.deleted_at is not null
  and loser.merged_into_partner_id is null
  and loser.tax_number is null
  and survivor.id <> loser.id
  and survivor.deleted_at is null
  and survivor.tax_number is null
  and survivor.company_id = loser.company_id
  and app.normalize_company_name(loser.name) is not null
  and app.normalize_company_name(survivor.name) = app.normalize_company_name(loser.name);

-- What a merge moved, so it can be moved back.
create table if not exists public.partner_merge (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  survivor_id uuid not null references public.partner (id),
  loser_id uuid not null references public.partner (id),
  -- The exact rows this merge repointed. An unmerge restores these and only
  -- these: an irat filed against the survivor afterwards was never the
  -- loser's, and dragging it along would invent history.
  document_ids uuid[] not null default '{}',
  ugy_ids uuid[] not null default '{}',
  -- The fields the survivor learned from the loser, so undoing gives them
  -- back instead of leaving the survivor wearing the other one's adószám.
  survivor_patch jsonb not null default '{}'::jsonb,
  merged_by uuid not null references public.app_user (id),
  undone_at timestamptz,
  undone_by uuid references public.app_user (id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint partner_merge_distinct check (survivor_id <> loser_id)
);

alter table public.partner_merge enable row level security;
alter table public.partner_merge force row level security;

drop trigger if exists partner_merge_touch_updated_at on public.partner_merge;
create trigger partner_merge_touch_updated_at
  before update on public.partner_merge
  for each row execute function app.touch_updated_at();

-- One open merge per retired partner: it is either merged away right now or
-- it is not, and two live claims on the same row would make an unmerge
-- ambiguous.
create unique index if not exists partner_merge_open_loser_key
  on public.partner_merge (loser_id) where undone_at is null;

create index if not exists partner_merge_company_idx
  on public.partner_merge (company_id, created_at desc);

drop policy if exists partner_merge_select on public.partner_merge;
create policy partner_merge_select on public.partner_merge
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

-- No insert or update policy: merge_partner() and unmerge_partner() are
-- SECURITY DEFINER and write this table themselves. A merge record that a
-- client could write by hand would not be a record of anything.

create or replace function app.protect_partner()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.id is distinct from old.id
     or new.company_id is distinct from old.company_id
     or new.created_at is distinct from old.created_at then
    raise exception 'partner identity is immutable (id, company)';
  end if;

  if new.merged_into_partner_id = new.id then
    raise exception 'a partner cannot be merged into itself';
  end if;

  -- Setting or clearing this column *is* the merge, and a merge only means
  -- anything if the iratok move in the same transaction, so it may only
  -- happen inside merge_partner()/unmerge_partner(). The setting is
  -- transaction-local and those two functions are the only things that set
  -- it. This is a discipline, not a security boundary — PostgREST offers no
  -- way to run SET, and RLS is what keeps other tenants out.
  if new.merged_into_partner_id is distinct from old.merged_into_partner_id
     and coalesce(current_setting('app.partner_merge', true), '') <> 'on' then
    raise exception 'partner merges go through merge_partner()';
  end if;

  -- A merged-away partner is history: it is what the older iratok used to be
  -- filed against, and editing it would rewrite what the register meant.
  if old.merged_into_partner_id is not null
     and coalesce(current_setting('app.partner_merge', true), '') <> 'on' then
    raise exception 'partner was merged into another one, undo the merge before editing it';
  end if;

  return new;
end;
$$;

-- 'partner_p' sorts before 'partner_t', so this runs before the updated_at
-- touch and never sees the timestamp it is about to be given.
drop trigger if exists partner_protect on public.partner;
create trigger partner_protect
  before update on public.partner
  for each row execute function app.protect_partner();

-- A Hungarian adószám is törzsszám (8) + ÁFA-kód (1) + megyekód (2). The ÁFA
-- code legitimately changes over a company's life; the törzsszám does not, so
-- that is the part that answers "is this the same taxpayer". Anything that is
-- not an 11-digit Hungarian number is compared whole, which is right for EU
-- VAT numbers and for the free text that occasionally lands in the field.
create or replace function app.tax_number_core(p_tax_number text)
returns text
language sql
immutable
set search_path = ''
as $$
  select case
    when p_tax_number is null then null
    when regexp_replace(upper(p_tax_number), '[^0-9A-Z]', '', 'g') ~ '^[0-9]{11}$'
      then left(regexp_replace(p_tax_number, '[^0-9]', '', 'g'), 8)
    else nullif(regexp_replace(upper(p_tax_number), '[^0-9A-Z]', '', 'g'), '')
  end;
$$;

-- Merging is a supervisory act on master data, so it takes the same role as
-- érvénytelenítés: an előadó files iratok, an owner or admin decides that two
-- names are one company.
create or replace function public.merge_partner(
  p_survivor_id uuid,
  p_loser_id uuid
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := (select auth.uid());
  v_role public.member_role;
  v_survivor public.partner%rowtype;
  v_loser public.partner%rowtype;
  v_first uuid;
  v_second uuid;
  v_documents uuid[];
  v_ugyek uuid[];
  v_patch jsonb := '{}'::jsonb;
  v_merge_id uuid;
begin
  if v_user_id is null then
    raise exception 'not authenticated';
  end if;

  if p_survivor_id = p_loser_id then
    raise exception 'a partner cannot be merged into itself';
  end if;

  -- Fixed lock order by id, so merging A into B and B into A at the same
  -- moment cannot deadlock.
  v_first  := least(p_survivor_id, p_loser_id);
  v_second := greatest(p_survivor_id, p_loser_id);
  perform 1 from public.partner where id = v_first for update;
  perform 1 from public.partner where id = v_second for update;

  select * into v_survivor from public.partner where id = p_survivor_id;
  if not found or v_survivor.company_id not in (select app.user_company_ids()) then
    raise exception 'partner not found';
  end if;

  select * into v_loser from public.partner where id = p_loser_id;
  if not found or v_loser.company_id not in (select app.user_company_ids()) then
    raise exception 'partner not found';
  end if;

  if v_survivor.company_id <> v_loser.company_id then
    raise exception 'partners belong to different companies';
  end if;

  select cm.role into v_role
  from public.company_member cm
  where cm.company_id = v_survivor.company_id and cm.user_id = v_user_id;

  if v_role is null or v_role not in ('owner', 'admin') then
    raise exception 'partner merge requires owner or admin role';
  end if;

  if v_survivor.merged_into_partner_id is not null
     or v_loser.merged_into_partner_id is not null then
    raise exception 'one of these partners was already merged into another one';
  end if;

  if v_survivor.deleted_at is not null then
    raise exception 'the surviving partner is retired';
  end if;

  -- The one refusal that cannot be argued with. Two different törzsszám are
  -- two different taxpayers, and no similarity between the names makes them
  -- the same company; the merged row would misattribute every irat on both
  -- sides and there would be no way to tell afterwards which was whose.
  if v_survivor.tax_number is not null
     and v_loser.tax_number is not null
     and app.tax_number_core(v_survivor.tax_number)
         is distinct from app.tax_number_core(v_loser.tax_number) then
    raise exception
      'partners have different tax numbers (% and %), they are not the same company',
      v_survivor.tax_number, v_loser.tax_number;
  end if;

  perform set_config('app.partner_merge', 'on', true);

  with moved as (
    update public.document
    set partner_id = p_survivor_id
    where partner_id = p_loser_id
    returning id
  )
  select coalesce(array_agg(id), '{}'::uuid[]) into v_documents from moved;

  with moved as (
    update public.ugy
    set partner_id = p_survivor_id
    where partner_id = p_loser_id
    returning id
  )
  select coalesce(array_agg(id), '{}'::uuid[]) into v_ugyek from moved;

  -- The survivor only ever fills its own blanks. Overwriting a value it
  -- already has would be the merge deciding which of two facts is true, and
  -- that is the user's call, made on the partner's own screen.
  if v_survivor.tax_number is null and v_loser.tax_number is not null then
    v_patch := v_patch || jsonb_build_object('tax_number', v_loser.tax_number);
  end if;
  if v_survivor.eu_tax_number is null and v_loser.eu_tax_number is not null then
    v_patch := v_patch || jsonb_build_object('eu_tax_number', v_loser.eu_tax_number);
  end if;
  if v_survivor.bank_account is null and v_loser.bank_account is not null then
    v_patch := v_patch || jsonb_build_object('bank_account', v_loser.bank_account);
  end if;
  if v_survivor.address is null and v_loser.address is not null then
    v_patch := v_patch || jsonb_build_object('address', v_loser.address);
  end if;
  if v_survivor.email is null and v_loser.email is not null then
    v_patch := v_patch || jsonb_build_object('email', v_loser.email);
  end if;
  if v_survivor.country is null and v_loser.country is not null then
    v_patch := v_patch || jsonb_build_object('country', v_loser.country);
  end if;
  if v_survivor.default_payment_term_days is null
     and v_loser.default_payment_term_days is not null then
    v_patch := v_patch
      || jsonb_build_object('default_payment_term_days', v_loser.default_payment_term_days);
  end if;
  if not v_survivor.is_supplier and v_loser.is_supplier then
    v_patch := v_patch || jsonb_build_object('is_supplier', true);
  end if;
  if not v_survivor.is_customer and v_loser.is_customer then
    v_patch := v_patch || jsonb_build_object('is_customer', true);
  end if;

  -- Retire the loser first, so the fields the survivor is about to copy are
  -- out of the unique indexes before the survivor takes them.
  update public.partner
  set deleted_at = coalesce(deleted_at, now()),
      merged_into_partner_id = p_survivor_id
  where id = p_loser_id;

  update public.partner
  set tax_number    = coalesce(tax_number, v_loser.tax_number),
      eu_tax_number = coalesce(eu_tax_number, v_loser.eu_tax_number),
      bank_account  = coalesce(bank_account, v_loser.bank_account),
      address       = coalesce(address, v_loser.address),
      email         = coalesce(email, v_loser.email),
      country       = coalesce(country, v_loser.country),
      default_payment_term_days =
        coalesce(default_payment_term_days, v_loser.default_payment_term_days),
      -- A merged partner is both, if either side was.
      is_supplier = is_supplier or v_loser.is_supplier,
      is_customer = is_customer or v_loser.is_customer
  where id = p_survivor_id;

  insert into public.partner_merge (
    company_id, survivor_id, loser_id, document_ids, ugy_ids, survivor_patch, merged_by
  )
  values (
    v_survivor.company_id, p_survivor_id, p_loser_id, v_documents, v_ugyek, v_patch, v_user_id
  )
  returning id into v_merge_id;

  -- The setting is transaction-local, which means it would stay on for
  -- whatever else this transaction did afterwards. Nothing else runs in this
  -- one today, but a guard that quietly switches itself off is not a guard.
  perform set_config('app.partner_merge', 'off', true);

  return jsonb_build_object(
    'merge_id', v_merge_id,
    'survivor_id', p_survivor_id,
    'loser_id', p_loser_id,
    'document_count', coalesce(array_length(v_documents, 1), 0),
    'ugy_count', coalesce(array_length(v_ugyek, 1), 0)
  );
end;
$$;

revoke execute on function public.merge_partner(uuid, uuid) from public, anon;
grant execute on function public.merge_partner(uuid, uuid) to authenticated;

create or replace function public.unmerge_partner(p_merge_id uuid)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := (select auth.uid());
  v_role public.member_role;
  v_merge public.partner_merge%rowtype;
  v_first uuid;
  v_second uuid;
  v_documents integer;
  v_ugyek integer;
  v_key text;
begin
  if v_user_id is null then
    raise exception 'not authenticated';
  end if;

  select * into v_merge from public.partner_merge where id = p_merge_id for update;

  if not found or v_merge.company_id not in (select app.user_company_ids()) then
    raise exception 'merge not found';
  end if;

  if v_merge.undone_at is not null then
    raise exception 'this merge was already undone';
  end if;

  select cm.role into v_role
  from public.company_member cm
  where cm.company_id = v_merge.company_id and cm.user_id = v_user_id;

  if v_role is null or v_role not in ('owner', 'admin') then
    raise exception 'undoing a partner merge requires owner or admin role';
  end if;

  v_first  := least(v_merge.survivor_id, v_merge.loser_id);
  v_second := greatest(v_merge.survivor_id, v_merge.loser_id);
  perform 1 from public.partner where id = v_first for update;
  perform 1 from public.partner where id = v_second for update;

  perform set_config('app.partner_merge', 'on', true);

  -- Take back what the survivor learned before the loser comes back to life,
  -- or the two would hold the same adószám at the same instant and the unique
  -- index would refuse it.
  for v_key in select jsonb_object_keys(v_merge.survivor_patch) loop
    if v_key in ('is_supplier', 'is_customer') then
      -- The survivor can only ever have gained these, so handing them back is
      -- a reset to false; the columns are NOT NULL.
      execute format('update public.partner set %I = false where id = $1', v_key)
        using v_merge.survivor_id;
    else
      execute format('update public.partner set %I = null where id = $1', v_key)
        using v_merge.survivor_id;
    end if;
  end loop;

  update public.partner
  set deleted_at = null,
      merged_into_partner_id = null
  where id = v_merge.loser_id;

  -- Only the rows this merge moved, and only if they are still where it put
  -- them. Anything filed against the survivor since then stays with it.
  with moved as (
    update public.document
    set partner_id = v_merge.loser_id
    where id = any (v_merge.document_ids) and partner_id = v_merge.survivor_id
    returning id
  )
  select count(*) into v_documents from moved;

  with moved as (
    update public.ugy
    set partner_id = v_merge.loser_id
    where id = any (v_merge.ugy_ids) and partner_id = v_merge.survivor_id
    returning id
  )
  select count(*) into v_ugyek from moved;

  -- The merge record is kept and marked undone, never deleted: it is the only
  -- evidence that the two rows were once treated as one company.
  update public.partner_merge
  set undone_at = now(), undone_by = v_user_id
  where id = p_merge_id;

  perform set_config('app.partner_merge', 'off', true);

  return jsonb_build_object(
    'merge_id', p_merge_id,
    'survivor_id', v_merge.survivor_id,
    'loser_id', v_merge.loser_id,
    'document_count', v_documents,
    'ugy_count', v_ugyek
  );
end;
$$;

revoke execute on function public.unmerge_partner(uuid) from public, anon;
grant execute on function public.unmerge_partner(uuid) to authenticated;

-- protect_ugy() freezes an archived ügy completely, which would make a
-- partner merge fail the moment one of the loser's ügyek had been put away.
-- Leaving an archived ügy pointing at the retired duplicate is exactly the
-- mess the merge exists to clear up, so partner_id — and nothing else — may
-- move on an archived ügy while a merge is running.
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
    if new is distinct from v_frozen then
      raise exception 'ugy is irattarazott, take it out of the archive before editing it';
    end if;
  end if;

  -- Only a genuine closing stamps the date. Taking an ugy back out of the
  -- archive returns it to 'lezart', and that must keep the day it was
  -- actually closed, not today.
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

  return new;
end;
$$;
