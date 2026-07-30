-- Helper schema, enums and shared functions.
-- Every later migration relies on app.user_company_ids() for RLS.

-- user_company_ids() references company_member, which is created in a later
-- migration — skip body validation at create time.
set check_function_bodies = off;

create schema if not exists app;

grant usage on schema app to authenticated, anon, service_role;

-- Kept vocabulary: two orthogonal dimensions instead of free-text categories.
create type public.direction as enum ('bejovo', 'kimeno', 'belso');

create type public.doc_kind as enum (
  'level', 'szamla', 'dijbekero', 'szerzodes', 'teljesites', 'nyilatkozat', 'egyeb'
);

create type public.processing_status as enum (
  'received', 'extracting', 'needs_review', 'iktatva',
  'extraction_failed', 'ervenytelenitve', 'duplicate'
);

create type public.member_role as enum ('owner', 'admin', 'eloado', 'viewer');

create type public.ugy_status as enum ('folyamatban', 'felfuggesztve', 'lezart', 'irattarazott');

create or replace function app.touch_updated_at()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

-- Companies of the signed-in user. SECURITY DEFINER so that policies built on it
-- do not recurse into company_member's own RLS; STABLE so the planner caches it
-- per statement.
create or replace function app.user_company_ids()
returns setof uuid
language sql
security definer
stable
set search_path = ''
as $$
  select company_id
  from public.company_member
  where user_id = (select auth.uid());
$$;
