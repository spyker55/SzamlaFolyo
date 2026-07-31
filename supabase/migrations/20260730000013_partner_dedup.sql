-- Stop creating a new partner row on every iktatás, and merge the ones that
-- already exist.
--
-- Partner resolution only ever looked at tax_number. "Tax number is the
-- reliable identifier; name is not" is true, but the fallback was to INSERT,
-- so every supplier without a Hungarian tax number — foreign companies,
-- private persons — got a fresh row each time an irat from them was iktatva.
-- One live example already: two partner rows for "Websupport s. r. o.", one
-- for the díjbekérő and one for the invoice answering it.
--
-- Name matching here deliberately does NOT strip the legal form, unlike the
-- ügy suggestion in src/lib/iktatas/ugy-suggest.ts. A wrong suggestion can be
-- declined; a wrong merge silently fuses two legal entities and cannot be
-- undone. "Nethely Kft." and "Nethely Bt." must stay apart.

-- Immutable so it can carry an index. Hungarian accents are folded with
-- translate() rather than unaccent(), which is only STABLE and would disqualify
-- the expression.
create or replace function app.normalize_company_name(p_name text)
returns text
language sql
immutable
set search_path = ''
as $$
  select nullif(
    regexp_replace(
      translate(
        lower(coalesce(p_name, '')),
        'áéíóöőúüűÁÉÍÓÖŐÚÜŰ',
        'aeiooouuuaeiooouuu'
      ),
      '[^a-z0-9]+', '', 'g'
    ),
    ''
  );
$$;

-- Merge what is already duplicated. The survivor is the oldest row, so the
-- partner keeps the id the earliest irat was filed against.
--
-- One statement with data-modifying CTEs: every branch sees the same snapshot,
-- so the loser set is computed once and all three updates agree on it.
with ranked as (
  select
    id,
    company_id,
    app.normalize_company_name(name) as norm,
    row_number() over (
      partition by company_id, app.normalize_company_name(name)
      order by created_at, id
    ) as rn
  from public.partner
  where tax_number is null
    and deleted_at is null
    and app.normalize_company_name(name) is not null
),
survivors as (
  select company_id, norm, id from ranked where rn = 1
),
losers as (
  select r.id as loser_id, s.id as survivor_id
  from ranked r
  join survivors s on s.company_id = r.company_id and s.norm = r.norm
  where r.rn > 1
),
doc_repointed as (
  update public.document d
  set partner_id = l.survivor_id
  from losers l
  where d.partner_id = l.loser_id
  returning 1
),
ugy_repointed as (
  update public.ugy u
  set partner_id = l.survivor_id
  from losers l
  where u.partner_id = l.loser_id
  returning 1
)
-- The losers are retired, not deleted: they are referenced by history and this
-- project does not physically remove records that an irat once pointed at.
update public.partner p
set deleted_at = now()
from losers l
where p.id = l.loser_id;

-- Lookup path for resolution.
create index if not exists partner_company_name_norm_idx
  on public.partner (company_id, app.normalize_company_name(name));

-- And the structural guarantee: among live partners with no tax number, one
-- normalized name means one row. Partners that do carry a tax number are
-- outside the predicate, because there the tax number is the identity and two
-- same-named companies are legitimately distinct.
--
-- NOTE: changing app.normalize_company_name() later requires REINDEX on both
-- of these — the stored index entries do not recompute themselves.
create unique index if not exists partner_company_name_norm_key
  on public.partner (company_id, app.normalize_company_name(name))
  where tax_number is null and deleted_at is null;

-- Partner resolution, lifted out of iktat_document() so that changing it never
-- again means re-pasting the whole iktatás transaction.
create or replace function app.resolve_partner(
  p_company_id uuid,
  p_name text,
  p_tax_number text,
  p_direction public.direction
)
returns uuid
language plpgsql
set search_path = ''
as $$
declare
  v_partner_id uuid;
  v_norm text := app.normalize_company_name(p_name);
begin
  if p_name is null and p_tax_number is null then
    return null;
  end if;

  -- 1. Tax number is the identity when we have one.
  if p_tax_number is not null then
    select id into v_partner_id
    from public.partner
    where company_id = p_company_id
      and tax_number = p_tax_number
      and deleted_at is null
    limit 1;
  end if;

  -- 2. Otherwise the normalized name, among partners whose tax number does not
  --    contradict the one on this irat. Prefer the richer record.
  if v_partner_id is null and v_norm is not null then
    select id into v_partner_id
    from public.partner
    where company_id = p_company_id
      and deleted_at is null
      and app.normalize_company_name(name) = v_norm
      and (tax_number is null or p_tax_number is null or tax_number = p_tax_number)
    order by tax_number nulls last, created_at
    limit 1;

    -- Learn the tax number the first time an irat carries it.
    if v_partner_id is not null and p_tax_number is not null then
      update public.partner
      set tax_number = p_tax_number
      where id = v_partner_id and tax_number is null;
    end if;
  end if;

  if v_partner_id is not null then
    return v_partner_id;
  end if;

  insert into public.partner (company_id, name, tax_number, is_supplier, is_customer)
  values (
    p_company_id,
    coalesce(p_name, p_tax_number),
    p_tax_number,
    p_direction = 'bejovo',
    p_direction = 'kimeno'
  )
  on conflict do nothing
  returning id into v_partner_id;

  -- Lost a race against a concurrent iktatás for the same new partner: the
  -- unique index refused the second insert, so read back the winner.
  if v_partner_id is null then
    select id into v_partner_id
    from public.partner
    where company_id = p_company_id
      and deleted_at is null
      and app.normalize_company_name(name) = v_norm
    order by created_at
    limit 1;
  end if;

  return v_partner_id;
end;
$$;

-- iktat_document() keeps its signature; only the partner block changes.
create or replace function public.iktat_document(
  p_document_id uuid,
  p_values jsonb default '{}'::jsonb,
  p_ugy_id uuid default null
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := (select auth.uid());
  v_doc public.document%rowtype;
  v_ugy public.ugy%rowtype;
  v_extraction public.extraction%rowtype;
  v_partner_id uuid;
  v_konyv_id uuid;
  v_konyv_closed timestamptz;
  v_prefix text;
  v_foszam integer;
  v_alszam integer;
  v_ev integer;
  v_iktatoszam text;
  v_ugy_id uuid;
  v_field text;
  v_human text;
  v_machine text;
  v_compare_fields text[] := array[
    'partner_name', 'partner_tax_number', 'targy', 'irat_szama', 'erkezett_at',
    'issue_date', 'due_date', 'direction', 'doc_kind', 'melleklet_db',
    'net_amount', 'vat_amount', 'gross_amount', 'currency'
  ];
begin
  if v_user_id is null then
    raise exception 'not authenticated';
  end if;

  -- Lock order is fixed everywhere: document first, then either the
  -- iktatokonyv row (new ugy) or the ugy row (new alszam) — never both, so
  -- the two paths cannot deadlock against each other.
  select * into v_doc
  from public.document
  where id = p_document_id
  for update;

  if not found then
    raise exception 'document not found';
  end if;

  if v_doc.company_id not in (select app.user_company_ids()) then
    raise exception 'document not found';
  end if;

  if v_doc.deleted_at is not null then
    raise exception 'document is deleted';
  end if;

  if v_doc.processing_status not in ('needs_review', 'extraction_failed') then
    raise exception 'document is not reviewable (status: %)', v_doc.processing_status;
  end if;

  v_doc.direction    := coalesce((p_values ->> 'direction')::public.direction, v_doc.direction);
  v_doc.doc_kind     := coalesce((p_values ->> 'doc_kind')::public.doc_kind, v_doc.doc_kind);
  v_doc.targy        := coalesce(nullif(trim(p_values ->> 'targy'), ''), v_doc.targy);
  v_doc.irat_szama   := coalesce(nullif(trim(p_values ->> 'irat_szama'), ''), v_doc.irat_szama);
  v_doc.erkezett_at  := coalesce((p_values ->> 'erkezett_at')::date, v_doc.erkezett_at);
  v_doc.issue_date   := coalesce((p_values ->> 'issue_date')::date, v_doc.issue_date);
  v_doc.due_date     := coalesce((p_values ->> 'due_date')::date, v_doc.due_date);
  v_doc.melleklet_db := coalesce((p_values ->> 'melleklet_db')::integer, v_doc.melleklet_db);
  v_doc.kezelesi_feljegyzes := coalesce(nullif(trim(p_values ->> 'kezelesi_feljegyzes'), ''), v_doc.kezelesi_feljegyzes);
  v_doc.currency     := coalesce(nullif(upper(trim(p_values ->> 'currency')), ''), v_doc.currency);
  v_doc.net_amount   := coalesce((p_values ->> 'net_amount')::numeric, v_doc.net_amount);
  v_doc.vat_amount   := coalesce((p_values ->> 'vat_amount')::numeric, v_doc.vat_amount);
  v_doc.gross_amount := coalesce((p_values ->> 'gross_amount')::numeric, v_doc.gross_amount);

  if v_doc.direction is null or v_doc.doc_kind is null then
    raise exception 'direction and doc_kind are required for iktatas';
  end if;

  -- Partner: an explicit id wins, otherwise resolve by tax number and name.
  if p_values ? 'partner_id' and nullif(p_values ->> 'partner_id', '') is not null then
    select id into v_partner_id
    from public.partner
    where id = (p_values ->> 'partner_id')::uuid and company_id = v_doc.company_id;
  end if;

  if v_partner_id is null then
    v_partner_id := app.resolve_partner(
      v_doc.company_id,
      nullif(trim(p_values ->> 'partner_name'), ''),
      nullif(trim(p_values ->> 'partner_tax_number'), ''),
      v_doc.direction
    );
  end if;

  v_doc.partner_id := coalesce(v_partner_id, v_doc.partner_id);

  select * into v_extraction
  from public.extraction
  where document_id = v_doc.id and error is null and parsed_fields is not null
  order by finished_at desc nulls last, created_at desc
  limit 1;

  if v_extraction.id is not null then
    foreach v_field in array v_compare_fields loop
      if p_values ? v_field then
        v_machine := v_extraction.parsed_fields ->> v_field;
        v_human := nullif(trim(p_values ->> v_field), '');
        if app.field_values_differ(v_field, v_machine, v_human) then
          insert into public.field_correction (
            company_id, document_id, extraction_id,
            field_name, machine_value, human_value, corrected_by
          )
          values (
            v_doc.company_id, v_doc.id, v_extraction.id,
            v_field, v_machine, v_human, v_user_id
          );
        end if;
      end if;
    end loop;
  end if;

  if p_ugy_id is null then
    v_ev := extract(year from v_doc.erkezett_at)::integer;

    insert into public.iktatokonyv (company_id, ev)
    values (v_doc.company_id, v_ev)
    on conflict (company_id, ev) do nothing;

    select id, prefix, next_foszam, closed_at
      into v_konyv_id, v_prefix, v_foszam, v_konyv_closed
    from public.iktatokonyv
    where company_id = v_doc.company_id and ev = v_ev
    for update;

    if v_konyv_closed is not null then
      raise exception 'iktatokonyv % is closed', v_ev;
    end if;

    update public.iktatokonyv
    set next_foszam = next_foszam + 1
    where id = v_konyv_id;

    insert into public.ugy (
      company_id, iktatokonyv_id, foszam, ev, targy, partner_id,
      eloado_user_id, irattari_jel, hatarido
    )
    values (
      v_doc.company_id, v_konyv_id, v_foszam, v_ev, v_doc.targy, v_doc.partner_id,
      v_user_id, nullif(trim(p_values ->> 'irattari_jel'), ''), v_doc.due_date
    )
    returning id into v_ugy_id;

    v_alszam := 1;
  else
    select * into v_ugy
    from public.ugy
    where id = p_ugy_id and company_id = v_doc.company_id
    for update;

    if not found then
      raise exception 'ugy not found';
    end if;

    if v_ugy.status in ('lezart', 'irattarazott') then
      raise exception 'ugy is %, no further irat can be filed under it', v_ugy.status;
    end if;

    select prefix, closed_at into v_prefix, v_konyv_closed
    from public.iktatokonyv
    where id = v_ugy.iktatokonyv_id;

    if v_konyv_closed is not null then
      raise exception 'iktatokonyv % is closed', v_ugy.ev;
    end if;

    v_ugy_id   := v_ugy.id;
    v_konyv_id := v_ugy.iktatokonyv_id;
    v_foszam   := v_ugy.foszam;
    v_ev       := v_ugy.ev;

    select coalesce(max(alszam), 0) + 1 into v_alszam
    from public.document
    where ugy_id = v_ugy_id;
  end if;

  v_iktatoszam := v_prefix || '/' || v_foszam || '-' || v_alszam || '/' || v_ev;

  update public.document
  set ugy_id = v_ugy_id,
      alszam = v_alszam,
      iktatoszam = v_iktatoszam,
      direction = v_doc.direction,
      doc_kind = v_doc.doc_kind,
      partner_id = v_doc.partner_id,
      targy = v_doc.targy,
      irat_szama = v_doc.irat_szama,
      erkezett_at = v_doc.erkezett_at,
      issue_date = v_doc.issue_date,
      due_date = v_doc.due_date,
      melleklet_db = v_doc.melleklet_db,
      kezelesi_feljegyzes = v_doc.kezelesi_feljegyzes,
      currency = v_doc.currency,
      net_amount = v_doc.net_amount,
      vat_amount = v_doc.vat_amount,
      gross_amount = v_doc.gross_amount,
      processing_status = 'iktatva'
  where id = v_doc.id;

  return jsonb_build_object(
    'document_id', v_doc.id,
    'ugy_id', v_ugy_id,
    'foszam', v_foszam,
    'alszam', v_alszam,
    'ev', v_ev,
    'iktatoszam', v_iktatoszam
  );
end;
$$;

revoke execute on function public.iktat_document(uuid, jsonb, uuid) from public, anon;
grant execute on function public.iktat_document(uuid, jsonb, uuid) to authenticated;
