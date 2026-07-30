-- Iktatokonyv: one per company per year in this milestone (schema allows more).
-- next_foszam is the gapless counter; it is only ever touched inside
-- iktat_document() under SELECT ... FOR UPDATE.

create table public.iktatokonyv (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  ev integer not null,
  nev text,
  prefix text not null default 'IKT',
  next_foszam integer not null default 1,
  opened_at timestamptz not null default now(),
  closed_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (company_id, ev)
);

alter table public.iktatokonyv enable row level security;
alter table public.iktatokonyv force row level security;

create trigger iktatokonyv_touch_updated_at
  before update on public.iktatokonyv
  for each row execute function app.touch_updated_at();

-- Members read the book; writes happen exclusively via iktat_document()
-- (SECURITY DEFINER), so there is no INSERT/UPDATE policy on purpose.
create policy iktatokonyv_select on public.iktatokonyv
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create table public.ugy (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  iktatokonyv_id uuid not null references public.iktatokonyv (id),
  foszam integer not null,
  ev integer not null,
  targy text,
  partner_id uuid references public.partner (id),
  eloado_user_id uuid references public.app_user (id),
  irattari_jel text,
  status public.ugy_status not null default 'folyamatban',
  hatarido date,
  parent_ugy_id uuid references public.ugy (id),
  opened_at timestamptz not null default now(),
  closed_at timestamptz,
  irattarba_helyezve_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (iktatokonyv_id, foszam)
);

alter table public.ugy enable row level security;
alter table public.ugy force row level security;

create trigger ugy_touch_updated_at
  before update on public.ugy
  for each row execute function app.touch_updated_at();

create index ugy_company_id_idx on public.ugy (company_id);

-- Created via iktat_document() only; members may edit metadata afterwards.
create policy ugy_select on public.ugy
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create policy ugy_update on public.ugy
  for update to authenticated
  using (company_id in (select app.user_company_ids()))
  with check (company_id in (select app.user_company_ids()));
