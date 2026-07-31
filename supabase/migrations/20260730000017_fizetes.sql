-- Payment tracking, so the fizetési naptár can tell an outstanding invoice
-- from a settled one. Without this every invoice ever filed would show as owed
-- forever, which makes the calendar worse than no calendar.
--
-- Deliberately not covered by app.protect_iktatott_document(): marking an irat
-- paid is a later fact *about* the irat, not a change to what was filed. The
-- iktatószám, the ügy and the filed field values stay as immutable as before.

alter table public.document
  add column if not exists fizetve_at date,
  add column if not exists fizetesi_megjegyzes text;

-- The calendar's only query: what does this company still owe, by due date.
create index if not exists document_fizetendo_idx
  on public.document (company_id, due_date)
  where fizetve_at is null and deleted_at is null;
