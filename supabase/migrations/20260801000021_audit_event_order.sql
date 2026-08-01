-- The audit log needs to say what happened first, and the version above could
-- not.
--
-- created_at defaulted to now(), which in Postgres is the *transaction's*
-- start time, not the moment of the insert. Everything the register does in
-- one transaction — iktat_document() opens the ügy, stamps the iktatószám and
-- files the irat; merge_partner() retires one partner and patches another —
-- therefore landed with byte-identical timestamps, and the feed fell back to
-- ordering by a random uuid. The log said the irat was filed before the ügy
-- existed about half the time.
--
-- clock_timestamp() reads the wall clock at the moment it is called, so two
-- inserts in the same transaction are always microseconds apart and in the
-- order they actually happened. It is also the honest value for a log: the
-- time an event occurred, not the time the transaction that contained it
-- began.

create or replace function app.audit_write(
  p_company_id uuid,
  p_action text,
  p_entity_type text,
  p_entity_id uuid,
  p_entity_label text,
  p_changes jsonb,
  p_context jsonb
)
returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_actor uuid := (select auth.uid());
  v_email text;
begin
  if p_company_id is null or p_entity_id is null then
    return;
  end if;

  if v_actor is not null then
    select u.email into v_email from public.app_user u where u.id = v_actor;
  end if;

  insert into public.audit_event (
    company_id, action, entity_type, entity_id, entity_label,
    actor_user_id, actor_email, actor_kind, changes, context,
    created_at, updated_at
  )
  values (
    p_company_id,
    p_action,
    p_entity_type,
    p_entity_id,
    nullif(btrim(coalesce(p_entity_label, '')), ''),
    v_actor,
    v_email,
    case when v_actor is null then 'system' else 'user' end,
    coalesce(p_changes, '{}'::jsonb),
    coalesce(p_context, '{}'::jsonb),
    clock_timestamp(),
    clock_timestamp()
  );
end;
$$;
