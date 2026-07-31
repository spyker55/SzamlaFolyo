-- The ugy screen is about to let people edit an ugy, and ugy_update is a
-- blanket policy: it allows every column. The foszam is half of the
-- iktatoszam printed on every irat filed under this ugy, so an UPDATE that
-- changed it would silently break the link between a number and its
-- documents. As with the document table, the rule cannot live in the policy
-- — it lives here, where neither the service role nor a SECURITY DEFINER
-- function can step around it.

create or replace function app.protect_ugy()
returns trigger
language plpgsql
as $$
declare
  -- The status machine, in the one form both the database and the test can
  -- read. 'folyamatban' never jumps straight to the archive: an ugy is closed
  -- first, which is the actual clerical workflow.
  v_allowed constant text[] := array[
    'folyamatban->felfuggesztve',
    'folyamatban->lezart',
    'felfuggesztve->folyamatban',
    'felfuggesztve->lezart',
    'lezart->folyamatban',
    'lezart->felfuggesztve',
    'lezart->irattarazott',
    'irattarazott->lezart'
  ];
begin
  -- The ugy's identity. None of this is editable, ever.
  if new.id is distinct from old.id
     or new.company_id is distinct from old.company_id
     or new.iktatokonyv_id is distinct from old.iktatokonyv_id
     or new.foszam is distinct from old.foszam
     or new.ev is distinct from old.ev
     or new.opened_at is distinct from old.opened_at
     or new.created_at is distinct from old.created_at
  then
    raise exception 'ugy identity is immutable (foszam, ev, iktatokonyv, company, opened_at)';
  end if;

  if new.status is distinct from old.status then
    if not (old.status::text || '->' || new.status::text = any (v_allowed)) then
      raise exception 'ugy status cannot go from % to %', old.status, new.status;
    end if;
  end if;

  -- An archived ugy is put away: only taking it back out is allowed, nothing
  -- else about it may change while it sits there.
  if old.status = 'irattarazott' and new.status = 'irattarazott' then
    if new is distinct from old then
      raise exception 'ugy is irattarazott, take it out of the archive before editing it';
    end if;
  end if;

  -- These two are facts about what happened, so the database writes them,
  -- not the caller. A client passing its own value is overwritten.
  -- Only a genuine closing stamps the date. Taking an ugy back out of the
  -- archive returns it to 'lezart', and that must keep the day it was
  -- actually closed, not today.
  if new.status = 'lezart' and old.status in ('folyamatban', 'felfuggesztve') then
    new.closed_at := now();
  elsif new.status in ('folyamatban', 'felfuggesztve') then
    new.closed_at := null;
  else
    new.closed_at := old.closed_at;
  end if;

  if new.status = 'irattarazott' and old.status <> 'irattarazott' then
    new.irattarba_helyezve_at := now();
  elsif new.status <> 'irattarazott' then
    -- The column means "in the archive since", so it does not survive being
    -- taken back out. A borrowing log is a separate thing, not this column.
    new.irattarba_helyezve_at := null;
  else
    new.irattarba_helyezve_at := old.irattarba_helyezve_at;
  end if;

  return new;
end;
$$;

drop trigger if exists ugy_protect on public.ugy;
create trigger ugy_protect
  before update on public.ugy
  for each row execute function app.protect_ugy();

-- The list screen orders by deadline within a company and filters by status.
create index if not exists ugy_company_status_idx
  on public.ugy (company_id, status, hatarido);
