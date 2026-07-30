-- extraction: one AI run over a document. raw_output is the model's response
-- as-is (saved even when parsing fails); parsed_fields is the normalized
-- machine value set that field_correction rows compare against.
-- Machine values are never overwritten by human ones.

create table public.extraction (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  document_id uuid not null references public.document (id),
  model_name text not null,
  model_version text,
  prompt_version text not null,
  raw_output jsonb,
  parsed_fields jsonb,
  field_confidence jsonb,
  started_at timestamptz,
  finished_at timestamptz,
  cost numeric(12, 6),
  error text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.extraction enable row level security;
alter table public.extraction force row level security;

create trigger extraction_touch_updated_at
  before update on public.extraction
  for each row execute function app.touch_updated_at();

create index extraction_document_idx on public.extraction (document_id);

-- Written by the worker (service role) only; members read.
create policy extraction_select on public.extraction
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create table public.field_correction (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  document_id uuid not null references public.document (id),
  extraction_id uuid references public.extraction (id),
  field_name text not null,
  machine_value text,
  human_value text,
  corrected_by uuid references public.app_user (id),
  corrected_at timestamptz not null default now(),
  created_at timestamptz not null default now()
);

alter table public.field_correction enable row level security;
alter table public.field_correction force row level security;

create index field_correction_document_idx on public.field_correction (document_id);

-- Written by iktat_document() (SECURITY DEFINER); members read.
create policy field_correction_select on public.field_correction
  for select to authenticated
  using (company_id in (select app.user_company_ids()));
