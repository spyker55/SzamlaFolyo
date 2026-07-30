-- Tenant core: company, app_user (mirror of auth.users), company_member.
-- RLS is enabled in the same migration that creates each table.

create table public.company (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  tax_number text,
  address text,
  default_currency char(3) not null default 'HUF',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.company enable row level security;
alter table public.company force row level security;

create trigger company_touch_updated_at
  before update on public.company
  for each row execute function app.touch_updated_at();

create table public.app_user (
  id uuid primary key references auth.users (id) on delete cascade,
  email text not null,
  full_name text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.app_user enable row level security;
alter table public.app_user force row level security;

create trigger app_user_touch_updated_at
  before update on public.app_user
  for each row execute function app.touch_updated_at();

create table public.company_member (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  user_id uuid not null references public.app_user (id),
  role public.member_role not null default 'eloado',
  accepted_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (company_id, user_id)
);

alter table public.company_member enable row level security;
alter table public.company_member force row level security;

create trigger company_member_touch_updated_at
  before update on public.company_member
  for each row execute function app.touch_updated_at();

create index company_member_user_id_idx on public.company_member (user_id);

-- Mirror every new auth user into app_user.
create or replace function app.handle_new_auth_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  insert into public.app_user (id, email, full_name)
  values (
    new.id,
    new.email,
    coalesce(new.raw_user_meta_data ->> 'full_name', null)
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function app.handle_new_auth_user();

-- Policies. No DELETE policy anywhere: rows are never physically deleted by clients.

create policy company_select on public.company
  for select to authenticated
  using (id in (select app.user_company_ids()));

create policy company_update on public.company
  for update to authenticated
  using (
    id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  )
  with check (
    id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

create policy app_user_select on public.app_user
  for select to authenticated
  using (
    id = (select auth.uid())
    or id in (
      select cm.user_id from public.company_member cm
      where cm.company_id in (select app.user_company_ids())
    )
  );

create policy app_user_update on public.app_user
  for update to authenticated
  using (id = (select auth.uid()))
  with check (id = (select auth.uid()));

create policy company_member_select on public.company_member
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

-- Company creation happens through this RPC so the company row and the owner
-- membership are created atomically (a plain INSERT policy would leave a
-- window where the creator is not yet a member).
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

  -- Milestone 1: one user belongs to exactly one company.
  if exists (select 1 from public.company_member where user_id = v_user_id) then
    raise exception 'user already belongs to a company';
  end if;

  insert into public.company (name, tax_number)
  values (trim(p_name), nullif(trim(p_tax_number), ''))
  returning id into v_company_id;

  insert into public.company_member (company_id, user_id, role, accepted_at)
  values (v_company_id, v_user_id, 'owner', now());

  return v_company_id;
end;
$$;

revoke execute on function public.create_company_with_owner(text, text) from public, anon;
grant execute on function public.create_company_with_owner(text, text) to authenticated;
