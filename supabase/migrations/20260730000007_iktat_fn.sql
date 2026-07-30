-- iktat_document(): the single transaction that
--   1. applies the human-approved field values to the document,
--   2. records field_correction rows where the human differed from the machine,
--   3. allocates a gapless foszam under SELECT ... FOR UPDATE,
--   4. creates the ugy and stamps the iktatoszam.
-- If anything fails, the rollback also rolls back next_foszam — that is why
-- this is a row lock and not a SEQUENCE.
--
-- SECURITY DEFINER: members must not hold raw UPDATE rights on
-- iktatokonyv.next_foszam, so membership is checked explicitly here.

-- Values differ? Amount-like fields compare as numbers so '1000' == '1000.0000'.
create or replace function app.field_values_differ(
  p_field text,
  p_machine text,
  p_human text
)
returns boolean
language plpgsql
immutable
set search_path = ''
as $$
begin
  if p_machine is null and p_human is null then
    return false;
  end if;
  if p_machine is null or p_human is null then
    return true;
  end if;
  if p_field in ('net_amount', 'vat_amount', 'gross_amount', 'melleklet_db') then
    begin
      return p_machine::numeric is distinct from p_human::numeric;
    exception when others then
      return p_machine is distinct from p_human;
    end;
  end if;
  return btrim(p_machine) is distinct from btrim(p_human);
end;
$$;

create or replace function public.iktat_document(
  p_document_id uuid,
  p_values jsonb default '{}'::jsonb
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := (select auth.uid());
  v_doc public.document%rowtype;
  v_extraction public.extraction%rowtype;
  v_partner_id uuid;
  v_partner_name text;
  v_partner_tax text;
  v_konyv_id uuid;
  v_prefix text;
  v_foszam integer;
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

  -- Lock order is fixed everywhere: document first, then iktatokonyv.
  select * into v_doc
  from public.document
  where id = p_document_id
  for update;

  if not found then
    raise exception 'document not found';
  end if;

  if v_doc.company_id not in (select app.user_company_ids()) then
    raise exception 'document not found';  -- do not leak existence across tenants
  end if;

  if v_doc.deleted_at is not null then
    raise exception 'document is deleted';
  end if;

  if v_doc.processing_status not in ('needs_review', 'extraction_failed') then
    raise exception 'document is not reviewable (status: %)', v_doc.processing_status;
  end if;

  -- Apply the approved values (whitelisted keys only).
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

  -- Partner: resolve by tax number within the company, otherwise create.
  v_partner_name := nullif(trim(p_values ->> 'partner_name'), '');
  v_partner_tax  := nullif(trim(p_values ->> 'partner_tax_number'), '');

  if p_values ? 'partner_id' and nullif(p_values ->> 'partner_id', '') is not null then
    select id into v_partner_id
    from public.partner
    where id = (p_values ->> 'partner_id')::uuid and company_id = v_doc.company_id;
  end if;

  if v_partner_id is null and v_partner_tax is not null then
    select id into v_partner_id
    from public.partner
    where company_id = v_doc.company_id
      and tax_number = v_partner_tax
      and deleted_at is null
    limit 1;
  end if;

  if v_partner_id is null and (v_partner_name is not null or v_partner_tax is not null) then
    insert into public.partner (company_id, name, tax_number, is_supplier, is_customer)
    values (
      v_doc.company_id,
      coalesce(v_partner_name, v_partner_tax),
      v_partner_tax,
      v_doc.direction = 'bejovo',
      v_doc.direction = 'kimeno'
    )
    returning id into v_partner_id;
  end if;

  v_doc.partner_id := coalesce(v_partner_id, v_doc.partner_id);

  -- Corrections: machine value stays untouched in extraction.parsed_fields,
  -- every human difference becomes a field_correction row.
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

  -- Gapless foszam allocation.
  v_ev := extract(year from v_doc.erkezett_at)::integer;

  insert into public.iktatokonyv (company_id, ev)
  values (v_doc.company_id, v_ev)
  on conflict (company_id, ev) do nothing;

  select id, prefix, next_foszam into v_konyv_id, v_prefix, v_foszam
  from public.iktatokonyv
  where company_id = v_doc.company_id and ev = v_ev
  for update;

  if (select closed_at from public.iktatokonyv where id = v_konyv_id) is not null then
    raise exception 'iktatokonyv % is closed', v_ev;
  end if;

  update public.iktatokonyv
  set next_foszam = next_foszam + 1
  where id = v_konyv_id;

  -- Milestone 1: every document opens a new ugy, alszam = 1.
  insert into public.ugy (
    company_id, iktatokonyv_id, foszam, ev, targy, partner_id,
    eloado_user_id, irattari_jel, hatarido
  )
  values (
    v_doc.company_id, v_konyv_id, v_foszam, v_ev, v_doc.targy, v_doc.partner_id,
    v_user_id, nullif(trim(p_values ->> 'irattari_jel'), ''), v_doc.due_date
  )
  returning id into v_ugy_id;

  v_iktatoszam := v_prefix || '/' || v_foszam || '-1/' || v_ev;

  update public.document
  set ugy_id = v_ugy_id,
      alszam = 1,
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
    'alszam', 1,
    'ev', v_ev,
    'iktatoszam', v_iktatoszam
  );
end;
$$;

revoke execute on function public.iktat_document(uuid, jsonb) from public, anon;
grant execute on function public.iktat_document(uuid, jsonb) to authenticated;
