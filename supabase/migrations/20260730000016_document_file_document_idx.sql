-- document_file only had (company_id, sha256), which serves duplicate
-- detection. The hottest read in the app is the other direction: the Beérkező
-- embeds document_file(original_filename) for up to 100 documents and reloads
-- every four seconds, and the review screen looks the file up by document_id.
--
-- The linter also reports a dozen other unindexed foreign keys — created_by,
-- corrected_by, ervenytelenitette, parent_ugy_id and so on. Those stay
-- unindexed on purpose: no query filters on them, and the rows they reference
-- are never hard-deleted, so an index there would serve the linter rather than
-- any workload.

create index if not exists document_file_document_id_idx
  on public.document_file (document_id);
