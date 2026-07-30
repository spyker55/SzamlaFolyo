-- document: one irat. Before iktatás: ugy_id / alszam / iktatoszam are NULL —
-- the ügy only comes to life when the foszam is allocated, otherwise a discarded
-- draft would burn a gapless number.

create table public.document (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  ugy_id uuid references public.ugy (id),
  alszam integer,
  iktatoszam text,
  direction public.direction,
  doc_kind public.doc_kind,
  processing_status public.processing_status not null default 'received',
  partner_id uuid references public.partner (id),
  targy text,
  irat_szama text,
  erkezett_at date not null default (now() at time zone 'Europe/Budapest')::date,
  issue_date date,
  due_date date,
  melleklet_db integer not null default 0,
  kezelesi_feljegyzes text,
  currency char(3),
  net_amount numeric(18, 4),
  vat_amount numeric(18, 4),
  gross_amount numeric(18, 4),
  source text not null default 'upload',
  duplicate_of_document_id uuid references public.document (id),
  extraction_attempts integer not null default 0,
  extraction_claimed_at timestamptz,
  created_by uuid references public.app_user (id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz,

  -- Amount and currency travel together.
  constraint document_amount_requires_currency check (
    (net_amount is null and vat_amount is null and gross_amount is null)
    or currency is not null
  ),
  -- Iktatott irat is fully identified; before that, nothing of it exists.
  constraint document_iktatas_consistent check (
    (ugy_id is null and alszam is null and iktatoszam is null)
    or (ugy_id is not null and alszam is not null and iktatoszam is not null)
  )
);

alter table public.document enable row level security;
alter table public.document force row level security;

create trigger document_touch_updated_at
  before update on public.document
  for each row execute function app.touch_updated_at();

create unique index document_ugy_alszam_key on public.document (ugy_id, alszam)
  where ugy_id is not null;

create unique index document_iktatoszam_key on public.document (company_id, iktatoszam)
  where iktatoszam is not null;

create index document_company_status_idx on public.document (company_id, processing_status);

create policy document_select on public.document
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create policy document_insert on public.document
  for insert to authenticated
  with check (
    company_id in (select app.user_company_ids())
    -- Clients create drafts only; iktatás fields are set by iktat_document().
    and ugy_id is null and alszam is null and iktatoszam is null
  );

create policy document_update on public.document
  for update to authenticated
  using (company_id in (select app.user_company_ids()))
  with check (company_id in (select app.user_company_ids()));

create table public.document_file (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  document_id uuid not null references public.document (id),
  storage_path text not null,
  original_filename text,
  mime_type text,
  page_count integer,
  sha256 text not null,
  uploaded_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.document_file enable row level security;
alter table public.document_file force row level security;

create trigger document_file_touch_updated_at
  before update on public.document_file
  for each row execute function app.touch_updated_at();

create index document_file_sha_idx on public.document_file (company_id, sha256);

create policy document_file_select on public.document_file
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create policy document_file_insert on public.document_file
  for insert to authenticated
  with check (company_id in (select app.user_company_ids()));
