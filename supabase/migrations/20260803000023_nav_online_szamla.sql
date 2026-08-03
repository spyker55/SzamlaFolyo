-- NAV Online Számla: mit tud a NAV, és mi hiányzik az iktatóból.
--
-- The direction of this integration is the first and most important decision,
-- so it is written down before any table.
--
-- **Számlafolyó queries NAV. It never reports to NAV.** Adatszolgáltatás — the
-- 3.0 manageInvoice operation — is the obligation of whoever *issued* the
-- invoice, and it is discharged by the program that issued it. Számlafolyó is
-- an iktató: it registers invoices somebody else wrote, including the ones the
-- company itself issued from its own számlázó. If this app also reported them,
-- the same invoice would arrive at NAV twice from two systems, and the second
-- report would be a false data submission with the company's tax number on it.
-- There is no configuration flag for that here, because there should not be
-- one to turn on by accident. Nothing in this migration writes to NAV.
--
-- What it does instead is the question an iktató is actually in a position to
-- answer, and cannot answer without NAV: **melyik számláról tud a NAV, ami
-- nálunk nincs iktatva?** Every other check in this system compares the
-- register against itself. A missing irat is invisible from the inside — it
-- looks exactly like an irat that was never sent. NAV holds the one list that
-- is independent of our inbox, because the supplier reported to it directly.
--
-- Three tables:
--
--   nav_credential — the technical user, encrypted. Only the query permission
--     is needed, so only the login, the password and the signing key are
--     stored. The exchange key (csereKulcs) exists only to decrypt an invoice
--     submission token, and this integration never submits, so it is never
--     asked for and never stored. Secrets that are not held cannot leak.
--
--   nav_invoice — what NAV said, kept as NAV said it, including the raw digest
--     row. Same discipline as extraction.raw_output: the authority's own words
--     are the record, and our interpretation of them is derived.
--
--   nav_sync — which range was queried, when, and how it ended. Without this
--     an empty result is ambiguous: it can mean "no invoice was issued to you"
--     or "nobody has looked".
--
-- One property worth stating: **a nav_invoice row cannot be deleted or
-- rewritten by anyone.** There is no delete policy, and the guard trigger
-- freezes its identity. The whole value of this table is that it is not ours
-- to edit — a discrepancy you can make disappear is not a control.

create table if not exists public.nav_credential (
  id uuid primary key default gen_random_uuid(),
  -- One taxpayer, one technical user. NAV binds the technical user to a tax
  -- number, so a second row for the same company could only be a second
  -- taxpayer, which is a different company as far as this app is concerned.
  company_id uuid not null unique references public.company (id),
  -- The 8-digit törzsszám, which is what NAV's <taxNumber> field takes — not
  -- the 11-digit adószám printed on the invoice.
  tax_number text not null,
  -- 'test' points at api-test.onlineszamla.nav.gov.hu, which has its own
  -- technical users and its own invoice data. Getting this wrong produces an
  -- authentication error, not wrong data, which is the failure we want.
  environment text not null default 'production',
  login text not null,
  -- AES-256-GCM ciphertext, keyed by NAV_SECRET_KEY from the environment and
  -- bound to the company_id as additional authenticated data. A database dump
  -- on its own does not contain the password, and a row copied into another
  -- company's tenancy fails to decrypt rather than authenticating as them.
  password_enc text not null,
  sign_key_enc text not null,
  -- Filled by the connection test, so the screen can say something better than
  -- "beállítva": whether these credentials have ever actually worked.
  last_ok_at timestamptz,
  last_error text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint nav_credential_tax_number_shape check (tax_number ~ '^[0-9]{8}$'),
  constraint nav_credential_environment check (environment in ('production', 'test'))
);

alter table public.nav_credential enable row level security;
alter table public.nav_credential force row level security;

drop trigger if exists nav_credential_touch_updated_at on public.nav_credential;
create trigger nav_credential_touch_updated_at
  before update on public.nav_credential
  for each row execute function app.touch_updated_at();

-- Same bar as érvénytelenítés, a partner merge and the irattári terv: an
-- előadó files iratok, an owner or admin hands the company's tax credentials
-- to a piece of software.
drop policy if exists nav_credential_select on public.nav_credential;
create policy nav_credential_select on public.nav_credential
  for select to authenticated
  using (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

drop policy if exists nav_credential_insert on public.nav_credential;
create policy nav_credential_insert on public.nav_credential
  for insert to authenticated
  with check (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

drop policy if exists nav_credential_update on public.nav_credential;
create policy nav_credential_update on public.nav_credential
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

-- Credentials are configuration, not history: revoking the technical user at
-- NAV and leaving a dead row here would be worse than removing it. The removal
-- itself is logged.
drop policy if exists nav_credential_delete on public.nav_credential;
create policy nav_credential_delete on public.nav_credential
  for delete to authenticated
  using (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

create or replace function app.protect_nav_credential()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.id is distinct from old.id
     or new.company_id is distinct from old.company_id
     or new.created_at is distinct from old.created_at then
    raise exception 'nav credential identity is immutable (id, company)';
  end if;
  return new;
end;
$$;

drop trigger if exists nav_credential_protect on public.nav_credential;
create trigger nav_credential_protect
  before update on public.nav_credential
  for each row execute function app.protect_nav_credential();

-- What NAV knows -------------------------------------------------------

create table if not exists public.nav_invoice (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  -- 'bejovo' = INBOUND, invoices issued **to** this taxpayer. 'kimeno' =
  -- OUTBOUND, what the company's own számlázó reported on its behalf.
  direction public.direction not null,
  -- NAV's transactionId plus the invoice's index inside that submission. This
  -- is the identity of a reported invoice at NAV, and it is what makes a
  -- re-sync idempotent: the same invoice number can legitimately appear
  -- several times (CREATE, then MODIFY, then STORNO), each its own row.
  transaction_key text not null,
  invoice_number text not null,
  -- Case- and whitespace-folded, so a re-sync and the matcher agree on what
  -- "the same number" means. Punctuation is *not* stripped: a slash inside an
  -- invoice number belongs to it, and folding it away could collide two real
  -- invoices from the same supplier.
  invoice_number_key text not null,
  invoice_operation text,
  invoice_category text,
  original_invoice_number text,
  -- Supplier for bejovo, customer for kimeno — the other party either way.
  partner_tax_number text,
  partner_tax_core text,
  -- A VAT group reports under the group's tax number, but the invoice is
  -- printed with the member's. Both are kept so that matching can accept
  -- either; discarding one would strand every invoice from a group member.
  partner_group_tax_core text,
  partner_name text,
  issue_date date,
  fulfillment_date date,
  payment_date date,
  currency char(3),
  net_amount numeric(18, 4),
  vat_amount numeric(18, 4),
  net_amount_huf numeric(18, 4),
  vat_amount_huf numeric(18, 4),
  -- When the supplier reported it, not when they issued it. The gap between
  -- the two is why a freshly issued invoice can be missing from NAV without
  -- anything being wrong.
  ins_date timestamptz,
  completeness boolean,
  nav_source text,
  -- NAV's own digest row, unaltered. Our columns are an interpretation of it;
  -- this is the thing itself.
  raw jsonb not null default '{}'::jsonb,
  first_seen_at timestamptz not null default now(),
  last_seen_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (company_id, direction, transaction_key)
);

alter table public.nav_invoice enable row level security;
alter table public.nav_invoice force row level security;

drop trigger if exists nav_invoice_touch_updated_at on public.nav_invoice;
create trigger nav_invoice_touch_updated_at
  before update on public.nav_invoice
  for each row execute function app.touch_updated_at();

create index if not exists nav_invoice_match_idx
  on public.nav_invoice (company_id, direction, invoice_number_key);

create index if not exists nav_invoice_issue_idx
  on public.nav_invoice (company_id, direction, issue_date);

-- Every member reads it. The point of the list is that somebody notices the
-- gap, and the person who files the iratok is the person best placed to.
drop policy if exists nav_invoice_select on public.nav_invoice;
create policy nav_invoice_select on public.nav_invoice
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

-- Only a sync writes here, and a sync is an owner/admin act because it spends
-- the company's NAV credentials.
drop policy if exists nav_invoice_insert on public.nav_invoice;
create policy nav_invoice_insert on public.nav_invoice
  for insert to authenticated
  with check (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

drop policy if exists nav_invoice_update on public.nav_invoice;
create policy nav_invoice_update on public.nav_invoice
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

-- No delete policy on purpose. A NAV row that says an invoice exists is
-- evidence, and the one thing nobody should be able to do with an inconvenient
-- discrepancy is make it go away.

create or replace function app.protect_nav_invoice()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.id is distinct from old.id
     or new.company_id is distinct from old.company_id
     or new.direction is distinct from old.direction
     or new.transaction_key is distinct from old.transaction_key
     or new.created_at is distinct from old.created_at then
    raise exception 'nav invoice identity is immutable (id, company, direction, transaction)';
  end if;

  -- A re-sync refreshes what NAV says. It cannot turn one reported invoice
  -- into a different one: that would be editing the authority's record.
  if new.invoice_number is distinct from old.invoice_number then
    raise exception 'the invoice number of a nav invoice cannot be changed';
  end if;

  return new;
end;
$$;

drop trigger if exists nav_invoice_protect on public.nav_invoice;
create trigger nav_invoice_protect
  before update on public.nav_invoice
  for each row execute function app.protect_nav_invoice();

-- When we last looked --------------------------------------------------

create table if not exists public.nav_sync (
  id uuid primary key default gen_random_uuid(),
  company_id uuid not null references public.company (id),
  direction public.direction not null,
  date_from date not null,
  date_to date not null,
  status text not null default 'fut',
  invoice_count integer not null default 0,
  new_count integer not null default 0,
  page_count integer not null default 0,
  error text,
  started_at timestamptz not null default now(),
  finished_at timestamptz,
  created_by uuid references public.app_user (id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint nav_sync_status check (status in ('fut', 'kesz', 'hiba')),
  constraint nav_sync_range check (date_from <= date_to)
);

alter table public.nav_sync enable row level security;
alter table public.nav_sync force row level security;

drop trigger if exists nav_sync_touch_updated_at on public.nav_sync;
create trigger nav_sync_touch_updated_at
  before update on public.nav_sync
  for each row execute function app.touch_updated_at();

create index if not exists nav_sync_company_idx
  on public.nav_sync (company_id, direction, started_at desc);

drop policy if exists nav_sync_select on public.nav_sync;
create policy nav_sync_select on public.nav_sync
  for select to authenticated
  using (company_id in (select app.user_company_ids()));

drop policy if exists nav_sync_insert on public.nav_sync;
create policy nav_sync_insert on public.nav_sync
  for insert to authenticated
  with check (
    company_id in (
      select cm.company_id from public.company_member cm
      where cm.user_id = (select auth.uid()) and cm.role in ('owner', 'admin')
    )
  );

drop policy if exists nav_sync_update on public.nav_sync;
create policy nav_sync_update on public.nav_sync
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

-- Audit ----------------------------------------------------------------

create or replace function app.audit_nav_credential()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  -- The two encrypted columns never reach the log. A changes entry would put
  -- the ciphertext in a table every member of the company can read, and an
  -- audit trail is not a place to widen the blast radius of a secret. What
  -- gets recorded is that they changed, which is the auditable fact.
  v_ignore constant text[] := array[
    'updated_at', 'password_enc', 'sign_key_enc', 'last_ok_at', 'last_error'
  ];
  v_changes jsonb;
begin
  if tg_op = 'INSERT' then
    perform app.audit_write(
      new.company_id, 'nav.kapcsolat_beallitva', 'nav_credential', new.id,
      new.tax_number,
      '{}'::jsonb,
      jsonb_build_object('environment', new.environment, 'login', new.login)
    );
    return null;
  end if;

  if tg_op = 'DELETE' then
    perform app.audit_write(
      old.company_id, 'nav.kapcsolat_torolve', 'nav_credential', old.id,
      old.tax_number, '{}'::jsonb,
      jsonb_build_object('environment', old.environment)
    );
    return null;
  end if;

  v_changes := app.audit_changes(to_jsonb(old), to_jsonb(new), v_ignore);

  if new.password_enc is distinct from old.password_enc then
    v_changes := v_changes || jsonb_build_object(
      'password_enc', jsonb_build_object('from', null, 'to', 'lecserélve'));
  end if;
  if new.sign_key_enc is distinct from old.sign_key_enc then
    v_changes := v_changes || jsonb_build_object(
      'sign_key_enc', jsonb_build_object('from', null, 'to', 'lecserélve'));
  end if;

  if v_changes = '{}'::jsonb then
    return null;
  end if;

  perform app.audit_write(
    new.company_id, 'nav.kapcsolat_modositva', 'nav_credential', new.id,
    new.tax_number, v_changes, '{}'::jsonb
  );
  return null;
end;
$$;

drop trigger if exists nav_credential_audit on public.nav_credential;
create trigger nav_credential_audit
  after insert or update or delete on public.nav_credential
  for each row execute function app.audit_nav_credential();

-- The individual nav_invoice rows are not audited: a single sync writes
-- hundreds of them, and burying the human decisions under machine noise is
-- how a napló stops being read. The sync itself is the event.
create or replace function app.audit_nav_sync()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.status = 'fut' or new.status is not distinct from old.status then
    return null;
  end if;

  perform app.audit_write(
    new.company_id,
    case when new.status = 'hiba' then 'nav.lekerdezes_hiba' else 'nav.lekerdezes' end,
    'nav_sync', new.id,
    to_char(new.date_from, 'YYYY-MM-DD') || ' – ' || to_char(new.date_to, 'YYYY-MM-DD'),
    '{}'::jsonb,
    jsonb_build_object(
      'direction', new.direction,
      'from', to_char(new.date_from, 'YYYY-MM-DD'),
      'to', to_char(new.date_to, 'YYYY-MM-DD'),
      'count', new.invoice_count,
      'new_count', new.new_count,
      'error', new.error
    )
  );
  return null;
end;
$$;

drop trigger if exists nav_sync_audit on public.nav_sync;
create trigger nav_sync_audit
  after update on public.nav_sync
  for each row execute function app.audit_nav_sync();
