-- Érvénytelenítés: the only thing that may ever happen to an iktatott irat.
--
-- The status already existed and the iktatókönyv already rendered it struck
-- through, but nothing could write it. Three decisions are baked in here:
--
--  * Who: owner and admin only. Filing an irat is an előadó's daily work;
--    withdrawing a filed one is a supervisory act, and this is the narrower
--    default — widening it later is a one-line change, narrowing it is not.
--  * Why: the reason is mandatory. An érvénytelenítés with no recorded reason
--    defeats the point of keeping the record at all.
--  * The ügy is left alone. Even when its last irat is withdrawn, closing the
--    ügy stays a human decision — an automatic close would quietly rewrite
--    what the register says happened.
--
-- The irat keeps its iktatószám: the number stays occupied, because reissuing
-- it would make the register lie about what was once filed under it.

alter table public.document
  add column if not exists ervenytelenites_indoka text,
  add column if not exists ervenytelenitette uuid references public.app_user (id),
  add column if not exists ervenytelenitve_at timestamptz;

-- Extends the retention guard: an érvénytelenítés is a formal act, so once
-- recorded it can be neither undone nor edited. Moving back out of
-- 'ervenytelenitve' was already impossible — the status check below only ever
-- permits that one destination — and now the record of it is fixed too.
create or replace function app.protect_iktatott_document()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if old.ervenytelenitve_at is not null
     and (new.ervenytelenitve_at is distinct from old.ervenytelenitve_at
          or new.ervenytelenites_indoka is distinct from old.ervenytelenites_indoka
          or new.ervenytelenitette is distinct from old.ervenytelenitette) then
    raise exception 'the record of an ervenytelenites cannot be changed';
  end if;

  -- Only rows that already carry an iktatószám are protected; iktat_document()
  -- writes these very columns on a row where old.iktatoszam is still null.
  if old.iktatoszam is null then
    return new;
  end if;

  if new.iktatoszam is distinct from old.iktatoszam
     or new.ugy_id is distinct from old.ugy_id
     or new.alszam is distinct from old.alszam then
    raise exception 'an iktatott irat keeps its iktatoszam, ugy and alszam';
  end if;

  if new.deleted_at is not null and old.deleted_at is null then
    raise exception 'an iktatott irat cannot be deleted, only ervenytelenitve';
  end if;

  if new.processing_status is distinct from old.processing_status
     and new.processing_status <> 'ervenytelenitve' then
    raise exception 'an iktatott irat can only move to ervenytelenitve';
  end if;

  return new;
end;
$$;

-- SECURITY DEFINER for the same reason iktat_document() is: the role check
-- belongs here, not in a policy that grants blanket UPDATE.
create or replace function public.ervenytelenit_document(
  p_document_id uuid,
  p_indoklas text
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := (select auth.uid());
  v_doc public.document%rowtype;
  v_role public.member_role;
  v_indoklas text := nullif(btrim(p_indoklas), '');
begin
  if v_user_id is null then
    raise exception 'not authenticated';
  end if;

  if v_indoklas is null or length(v_indoklas) < 5 then
    raise exception 'ervenytelenites requires a reason';
  end if;

  select * into v_doc
  from public.document
  where id = p_document_id
  for update;

  if not found or v_doc.company_id not in (select app.user_company_ids()) then
    raise exception 'document not found';  -- do not leak existence across tenants
  end if;

  select cm.role into v_role
  from public.company_member cm
  where cm.company_id = v_doc.company_id and cm.user_id = v_user_id;

  if v_role is null or v_role not in ('owner', 'admin') then
    raise exception 'ervenytelenites requires owner or admin role';
  end if;

  if v_doc.iktatoszam is null then
    raise exception 'only an iktatott irat can be ervenytelenitve';
  end if;

  if v_doc.processing_status = 'ervenytelenitve' then
    raise exception 'document is already ervenytelenitve';
  end if;

  update public.document
  set processing_status = 'ervenytelenitve',
      ervenytelenites_indoka = v_indoklas,
      ervenytelenitette = v_user_id,
      ervenytelenitve_at = now()
  where id = v_doc.id;

  return jsonb_build_object(
    'document_id', v_doc.id,
    'iktatoszam', v_doc.iktatoszam
  );
end;
$$;

revoke execute on function public.ervenytelenit_document(uuid, text) from public, anon;
grant execute on function public.ervenytelenit_document(uuid, text) to authenticated;
