-- Audit napló: who did what to the register, and when.
--
-- Everything the app already refuses to forget — an irat is never deleted, an
-- érvénytelenítés is never undone, a merge records the rows it moved — says
-- what the data looks like now. None of it says how it got there. "Ki írta át
-- ennek az iratnak a tárgyát?" and "mikor lett ez az ügy lezárva, és ki
-- zárta?" have no answer today, and in an iktatórendszer those are the
-- questions that decide whether the register can be believed at all.
--
-- Three decisions carry this migration.
--
-- One: the log is written by triggers, not by the application. Server actions,
-- the extraction worker, the e-mail ingest and a psql session all reach the
-- same tables through different code paths, and an audit trail that only
-- covers the paths someone remembered to instrument is worse than none — it
-- looks complete while being silent about exactly the write nobody expected.
-- The trigger sees every write, including the ones made with the service role.
--
-- Two: the log is append-only, and that is enforced where it cannot be
-- stepped around. Clients get SELECT and nothing else — no INSERT grant, so
-- nobody can forge an entry — and a BEFORE UPDATE OR DELETE trigger raises
-- for every role, service_role included. RLS is enabled but deliberately not
-- FORCEd: the trigger functions are SECURITY DEFINER and insert as the table
-- owner, and forcing RLS on the owner would mean writing an INSERT policy —
-- a permission that, once it exists, is the very thing this table must not
-- have.
--
-- Three: every member of the company may read it. Each entry describes a row
-- the member can already open, so hiding who touched it protects nobody, and
-- "ki iktatta ezt?" is an everyday question for the people doing the filing,
-- not a supervisory one. Narrowing this to owner/admin later is a change to
-- one policy.
--
-- The log starts empty and starts now. Nothing before this migration can be
-- reconstructed, and inventing plausible entries for it would be the one
-- thing an audit trail must never do.

create table if not exists public.audit_event (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  -- 'document.iktatva', 'ugy.statusz', 'partner.osszevonva'… The vocabulary is
  -- text rather than an enum: an enum would make every new event type a
  -- migration that must land before the code that writes it, and these are
  -- labels for humans, not values anything branches on in the database.
  action text not null,
  entity_type text not null,
  entity_id uuid not null,
  -- What the row was called at the time. An iktatószám never changes, but a
  -- partner's name does, and a log that renders today's name onto last year's
  -- event describes something that did not happen.
  entity_label text,
  -- Null when the write came from the extraction worker, the cron sweep or the
  -- e-mail ingest: those run with the service role and have no auth.uid().
  actor_user_id uuid references public.app_user (id),
  -- Snapshotted for the same reason as entity_label: the log has to stay
  -- readable after a colleague is removed from the company.
  actor_email text,
  actor_kind text not null default 'user',
  -- { "targy": { "from": "…", "to": "…" } } — only the columns that actually
  -- moved, so a row is small and reads as a sentence.
  changes jsonb not null default '{}'::jsonb,
  -- Facts that are not column changes: the export's period, the file's sha256,
  -- which partner a merge kept.
  context jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  -- Present because every table in this schema has it. Here it can never move
  -- off created_at — the append-only trigger below refuses the UPDATE that
  -- would touch it — and that is the point: same shape everywhere, no
  -- exception to remember.
  updated_at timestamptz not null default now()
);

alter table public.audit_event enable row level security;

-- The feed, newest first.
create index if not exists audit_event_company_created_idx
  on public.audit_event (company_id, created_at desc);

-- One irat's or one ügy's own history, shown on its screen.
create index if not exists audit_event_entity_idx
  on public.audit_event (company_id, entity_type, entity_id, created_at desc);

-- "Mit csinált ma X?"
create index if not exists audit_event_actor_idx
  on public.audit_event (company_id, actor_user_id, created_at desc);

drop policy if exists audit_event_select on public.audit_event;
create policy audit_event_select on public.audit_event
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

-- No INSERT, UPDATE or DELETE policy, and no grant to match: the triggers
-- below are the only writers. Supabase grants everything on new public tables
-- to these roles by default, so the revoke is not decoration.
revoke all on public.audit_event from anon;
revoke insert, update, delete, truncate on public.audit_event from authenticated, service_role;
grant select on public.audit_event to authenticated;

create or replace function app.deny_audit_change()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'audit_event is append-only: entries cannot be changed or removed';
end;
$$;

-- FOR EACH ROW on the write side, FOR EACH STATEMENT on TRUNCATE (a row-level
-- truncate trigger is not a thing). Together they close the two ways a row
-- could leave this table.
drop trigger if exists audit_event_append_only on public.audit_event;
create trigger audit_event_append_only
  before update or delete on public.audit_event
  for each row execute function app.deny_audit_change();

drop trigger if exists audit_event_no_truncate on public.audit_event;
create trigger audit_event_no_truncate
  before truncate on public.audit_event
  for each statement execute function app.deny_audit_change();

-- The one place that writes an entry. SECURITY DEFINER so the trigger can
-- insert into a table its caller has no INSERT grant on — which is exactly
-- the arrangement that makes forging an entry impossible from a client.
create or replace function app.audit_write(
  p_company_id uuid,
  p_action text,
  p_entity_type text,
  p_entity_id uuid,
  p_entity_label text,
  p_changes jsonb,
  p_context jsonb
)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_actor uuid := (select auth.uid());
  v_email text;
begin
  if p_company_id is null or p_entity_id is null then
    return;
  end if;

  if v_actor is not null then
    select u.email into v_email from public.app_user u where u.id = v_actor;
  end if;

  insert into public.audit_event (
    company_id, action, entity_type, entity_id, entity_label,
    actor_user_id, actor_email, actor_kind, changes, context
  )
  values (
    p_company_id,
    p_action,
    p_entity_type,
    p_entity_id,
    nullif(btrim(coalesce(p_entity_label, '')), ''),
    v_actor,
    v_email,
    case when v_actor is null then 'system' else 'user' end,
    coalesce(p_changes, '{}'::jsonb),
    coalesce(p_context, '{}'::jsonb)
  );
end;
$$;

-- The diff, as jsonb. Columns in p_ignore never appear — updated_at moves on
-- every write and would otherwise be the only content of half the entries.
create or replace function app.audit_changes(p_old jsonb, p_new jsonb, p_ignore text[])
returns jsonb
language sql
immutable
set search_path = ''
as $$
  select coalesce(
    jsonb_object_agg(k, jsonb_build_object('from', p_old -> k, 'to', p_new -> k)),
    '{}'::jsonb
  )
  from jsonb_object_keys(p_new) as k
  where not (k = any (p_ignore))
    and (p_old -> k) is distinct from (p_new -> k);
$$;

-- document ---------------------------------------------------------------

create or replace function app.audit_document()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  -- extraction_attempts and extraction_claimed_at are the worker's own
  -- bookkeeping: they move every time a row is picked up or retried, and none
  -- of it is a fact about the irat.
  v_ignore constant text[] := array['updated_at', 'extraction_attempts', 'extraction_claimed_at'];
  v_changes jsonb;
  v_action text;
  v_label text;
begin
  v_label := coalesce(new.iktatoszam, new.targy);

  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.company_id, 'document.erkeztetve', 'document', new.id, v_label,
      '{}'::jsonb,
      jsonb_build_object('source', new.source, 'processing_status', new.processing_status)
    );
    return null;
  end if;

  -- The worker claiming a row is machinery, not history. The result of the
  -- extraction is logged below; the moment it started is not interesting and
  -- would double the size of the log.
  if new.processing_status = 'extracting' and old.processing_status is distinct from 'extracting' then
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);
  if v_changes = '{}'::jsonb then
    return null;
  end if;

  -- Most specific first: iktatás also changes processing_status, and calling
  -- that entry 'modositva' would bury the single most important event in the
  -- irat's life among the metadata edits.
  if old.iktatoszam is null and new.iktatoszam is not null then
    v_action := 'document.iktatva';
  elsif new.processing_status = 'ervenytelenitve'
        and old.processing_status is distinct from 'ervenytelenitve' then
    v_action := 'document.ervenytelenitve';
  elsif new.deleted_at is not null and old.deleted_at is null then
    v_action := 'document.elvetve';
  elsif new.deleted_at is null and old.deleted_at is not null then
    v_action := 'document.visszaallitva';
  elsif new.fizetve_at is not null and old.fizetve_at is null then
    v_action := 'document.fizetve';
  elsif new.fizetve_at is null and old.fizetve_at is not null then
    v_action := 'document.fizetes_visszavonva';
  elsif new.processing_status = 'duplicate'
        and old.processing_status is distinct from 'duplicate' then
    v_action := 'document.duplikatum';
  elsif new.processing_status = 'extraction_failed'
        and old.processing_status is distinct from 'extraction_failed' then
    v_action := 'document.feldolgozas_sikertelen';
  elsif new.processing_status = 'needs_review'
        and old.processing_status is distinct from 'needs_review' then
    v_action := 'document.feldolgozva';
  else
    v_action := 'document.modositva';
  end if;

  perform app.audit_write(
    new.company_id, v_action, 'document', new.id, v_label, v_changes, '{}'::jsonb
  );
  return null;
end;
$$;

drop trigger if exists document_audit on public.document;
create trigger document_audit
  after insert or update on public.document
  for each row execute function app.audit_document();

-- The sha256 of the file that was actually filed is the strongest thing this
-- log can record: it says the bytes behind an iktatószám are still the bytes
-- that were filed under it.
create or replace function app.audit_document_file()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_label text;
begin
  select coalesce(d.iktatoszam, d.targy) into v_label
  from public.document d where d.id = new.document_id;

  perform app.audit_write(
    new.company_id, 'document.fajl_csatolva', 'document', new.document_id, v_label,
    '{}'::jsonb,
    jsonb_build_object(
      'original_filename', new.original_filename,
      'mime_type', new.mime_type,
      'sha256', new.sha256
    )
  );
  return null;
end;
$$;

drop trigger if exists document_file_audit on public.document_file;
create trigger document_file_audit
  after insert on public.document_file
  for each row execute function app.audit_document_file();

-- ugy --------------------------------------------------------------------

create or replace function app.audit_ugy()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_ignore constant text[] := array['updated_at'];
  v_changes jsonb;
  v_action text;
  v_prefix text;
  v_label text;
begin
  select k.prefix into v_prefix
  from public.iktatokonyv k where k.id = new.iktatokonyv_id;
  v_label := coalesce(v_prefix, 'IKT') || '/' || new.foszam || '/' || new.ev;

  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.company_id, 'ugy.megnyitva', 'ugy', new.id, v_label,
      '{}'::jsonb,
      jsonb_build_object('targy', new.targy)
    );
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);
  if v_changes = '{}'::jsonb then
    return null;
  end if;

  if new.status is distinct from old.status then
    v_action := 'ugy.statusz';
  else
    v_action := 'ugy.modositva';
  end if;

  perform app.audit_write(new.company_id, v_action, 'ugy', new.id, v_label, v_changes, '{}'::jsonb);
  return null;
end;
$$;

drop trigger if exists ugy_audit on public.ugy;
create trigger ugy_audit
  after insert or update on public.ugy
  for each row execute function app.audit_ugy();

-- partner ----------------------------------------------------------------

create or replace function app.audit_partner()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_ignore constant text[] := array['updated_at'];
  v_changes jsonb;
  v_action text;
  v_other text;
begin
  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.company_id, 'partner.letrehozva', 'partner', new.id, new.name,
      '{}'::jsonb,
      jsonb_build_object('tax_number', new.tax_number)
    );
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);
  if v_changes = '{}'::jsonb then
    return null;
  end if;

  if new.merged_into_partner_id is not null and old.merged_into_partner_id is null then
    select p.name into v_other from public.partner p where p.id = new.merged_into_partner_id;
    perform app.audit_write(
      new.company_id, 'partner.osszevonva', 'partner', new.id, new.name, v_changes,
      jsonb_build_object('survivor_id', new.merged_into_partner_id, 'survivor_name', v_other)
    );
    return null;
  end if;

  if new.merged_into_partner_id is null and old.merged_into_partner_id is not null then
    select p.name into v_other from public.partner p where p.id = old.merged_into_partner_id;
    perform app.audit_write(
      new.company_id, 'partner.szetvalasztva', 'partner', new.id, new.name, v_changes,
      jsonb_build_object('survivor_id', old.merged_into_partner_id, 'survivor_name', v_other)
    );
    return null;
  end if;

  if new.deleted_at is not null and old.deleted_at is null then
    v_action := 'partner.torolve';
  elsif new.deleted_at is null and old.deleted_at is not null then
    v_action := 'partner.visszaallitva';
  else
    v_action := 'partner.modositva';
  end if;

  perform app.audit_write(
    new.company_id, v_action, 'partner', new.id, new.name, v_changes, '{}'::jsonb
  );
  return null;
end;
$$;

drop trigger if exists partner_audit on public.partner;
create trigger partner_audit
  after insert or update on public.partner
  for each row execute function app.audit_partner();

-- who has access ---------------------------------------------------------

create or replace function app.audit_company_member()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_ignore constant text[] := array['updated_at'];
  v_changes jsonb;
  v_label text;
begin
  select u.email into v_label from public.app_user u where u.id = new.user_id;

  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.company_id, 'tag.hozzaadva', 'company_member', new.id, v_label,
      '{}'::jsonb,
      jsonb_build_object('role', new.role)
    );
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);
  if v_changes = '{}'::jsonb then
    return null;
  end if;

  perform app.audit_write(
    new.company_id,
    case when new.role is distinct from old.role then 'tag.szerepkor' else 'tag.modositva' end,
    'company_member', new.id, v_label, v_changes, '{}'::jsonb
  );
  return null;
end;
$$;

drop trigger if exists company_member_audit on public.company_member;
create trigger company_member_audit
  after insert or update on public.company_member
  for each row execute function app.audit_company_member();

create or replace function app.audit_company()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_ignore constant text[] := array['updated_at'];
  v_changes jsonb;
begin
  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.id, 'ceg.letrehozva', 'company', new.id, new.name, '{}'::jsonb, '{}'::jsonb
    );
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);
  if v_changes = '{}'::jsonb then
    return null;
  end if;

  perform app.audit_write(
    new.id, 'ceg.modositva', 'company', new.id, new.name, v_changes, '{}'::jsonb
  );
  return null;
end;
$$;

drop trigger if exists company_audit on public.company;
create trigger company_audit
  after insert or update on public.company
  for each row execute function app.audit_company();

-- an export leaves the building ------------------------------------------

-- The one event no trigger can see: nothing in the database changes when a
-- month of iratok is downloaded as a ZIP, and that is precisely the event
-- worth recording — it is the moment the company's documents leave the system.
--
-- Narrow on purpose. It takes no company_id (the caller's membership decides
-- it), no entity and no free-form action, so it cannot be turned into a
-- general "write me an audit entry" endpoint.
create or replace function public.log_export(
  p_format text,
  p_from date,
  p_to date,
  p_basis text,
  p_direction text,
  p_count integer
)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user uuid := (select auth.uid());
  v_company uuid;
begin
  if v_user is null then
    raise exception 'not authenticated';
  end if;

  if p_format not in ('csv', 'zip') then
    raise exception 'unknown export format: %', p_format;
  end if;

  select cm.company_id into v_company
  from public.company_member cm
  where cm.user_id = v_user
  limit 1;

  if v_company is null then
    return;
  end if;

  perform app.audit_write(
    v_company, 'export.letoltve', 'company', v_company, null,
    '{}'::jsonb,
    jsonb_build_object(
      'format', p_format,
      'from', p_from,
      'to', p_to,
      'basis', p_basis,
      'direction', p_direction,
      'count', p_count
    )
  );
end;
$$;

revoke execute on function public.log_export(text, date, date, text, text, integer) from public, anon;
grant execute on function public.log_export(text, date, date, text, text, integer) to authenticated;
