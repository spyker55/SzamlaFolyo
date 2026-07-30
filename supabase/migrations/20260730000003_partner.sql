create table public.partner (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  name text not null,
  tax_number text,
  eu_tax_number text,
  country text,
  is_supplier boolean not null default false,
  is_customer boolean not null default false,
  default_payment_term_days integer,
  note text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz
);

alter table public.partner enable row level security;
alter table public.partner force row level security;

create trigger partner_touch_updated_at
  before update on public.partner
  for each row execute function app.touch_updated_at();

-- Tax number is the reliable identifier; name is not.
create index partner_company_tax_number_idx on public.partner (company_id, tax_number);

create policy partner_select on public.partner
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create policy partner_insert on public.partner
  for insert to authenticated
  with check (company_id in (select app.user_company_ids()));

create policy partner_update on public.partner
  for update to authenticated
  using (company_id in (select app.user_company_ids()))
  with check (company_id in (select app.user_company_ids()));
