-- Let a document be discarded before iktatás, and make the retention rule
-- structural instead of conventional.
--
-- Two different things were conflated. An irat that has been iktatva carries a
-- gapless főszám and must never disappear — only érvénytelenítés. But a file
-- sitting in the Beérkező has no iktatószám, no ügy and no number to leave a
-- hole in: a duplicate or a misdelivered document simply should not be there,
-- and until now there was no way to remove it.
--
-- The guard below is the part that was actually missing. document_update lets
-- any member write any column, so "iktatott iratot fizikailag törölni tilos"
-- rested on nobody trying — a plain UPDATE could set deleted_at on an iktatott
-- irat, or rewrite its iktatószám.

alter type public.processing_status add value if not exists 'elvetve' after 'duplicate';

create or replace function app.protect_iktatott_document()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
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

-- Fires for every writer, including SECURITY DEFINER functions and the service
-- role: this is the retention rule itself, not a client-side convenience.
create trigger document_protect_iktatott
  before update on public.document
  for each row execute function app.protect_iktatott_document();
