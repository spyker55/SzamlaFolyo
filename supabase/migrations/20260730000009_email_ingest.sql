-- E-mail beérkeztetés: minden cég kap egy kitalálhatatlan fogadó címet, és a
-- beérkezett levelek nyoma tábla szinten megmarad.

create type public.inbound_email_status as enum (
  'processed',       -- legalább egy melléklet iratként létrejött
  'no_attachment',   -- megérkezett, de nem volt feldolgozható melléklete
  'rejected',        -- nem sikerült feldolgozni (ok az error mezőben)
  'duplicate'        -- ugyanaz a levél már fel volt dolgozva
);

-- A cím lokális része. 16 hexa karakter = 64 bit véletlen, tehát nem
-- kitalálható; gen_random_uuid() miatt nem kell hozzá pgcrypto.
alter table public.company
  add column inbox_token text not null
    default substr(replace(gen_random_uuid()::text, '-', ''), 1, 16);

alter table public.company add constraint company_inbox_token_unique unique (inbox_token);

-- A feladó ismertségét nem kézzel karbantartott lista adja, hanem a saját
-- döntés: ha egy e-mailből jött iratot leiktatnak, a feladó ismertté válik.
create table public.email_sender (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  address text not null,
  trusted boolean not null default false,
  first_seen_at timestamptz not null default now(),
  last_seen_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (company_id, address)
);

create table public.inbound_email (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  provider_message_id text not null,
  mail_from text,
  mail_to text,
  subject text,
  sender_known boolean not null default false,
  attachment_count integer not null default 0,
  document_count integer not null default 0,
  status public.inbound_email_status not null,
  error text,
  -- A nyers webhook-payload azelőtt megmarad, hogy bármit értelmeznénk
  -- belőle — ugyanaz az elv, mint az extraction.raw_output-nál.
  raw_payload jsonb,
  received_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  -- A webhookok ismétlődhetnek; ugyanaz a levél nem eredményez új iratot.
  unique (company_id, provider_message_id)
);

alter table public.document
  add column inbound_email_id uuid references public.inbound_email (id);

create index document_inbound_email_id_idx on public.document (inbound_email_id);
create index inbound_email_company_received_idx
  on public.inbound_email (company_id, received_at desc);

-- RLS az első naptól, ahogy minden üzleti táblán.
alter table public.email_sender enable row level security;
alter table public.email_sender force row level security;
alter table public.inbound_email enable row level security;
alter table public.inbound_email force row level security;

-- Olvasás a saját cégen belül. Írni csak a webhook ír, service_role-lal,
-- ami megkerüli az RLS-t — ezért nincs insert/update policy.
create policy email_sender_select on public.email_sender
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create policy inbound_email_select on public.inbound_email
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

create trigger email_sender_touch_updated_at
  before update on public.email_sender
  for each row execute function app.touch_updated_at();

create trigger inbound_email_touch_updated_at
  before update on public.inbound_email
  for each row execute function app.touch_updated_at();

-- Ha egy e-mailből érkezett irat iktatásra kerül, a feladó onnantól ismert.
-- Külön triggerben, hogy az iktat_document() — a rendszer legkritikusabb
-- függvénye — érintetlen maradjon.
create or replace function app.trust_sender_on_iktatas()
returns trigger language plpgsql security definer set search_path = ''
as $$
declare
  v_from text;
begin
  select ie.mail_from into v_from
    from public.inbound_email ie
   where ie.id = new.inbound_email_id;

  if v_from is null then
    return new;
  end if;

  update public.email_sender
     set trusted = true
   where company_id = new.company_id
     and address = lower(v_from);

  return new;
end;
$$;

create trigger document_trust_sender_on_iktatas
  after update of processing_status on public.document
  for each row
  when (new.processing_status = 'iktatva'
        and old.processing_status is distinct from 'iktatva'
        and new.inbound_email_id is not null)
  execute function app.trust_sender_on_iktatas();
