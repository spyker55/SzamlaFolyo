-- Widen doc_kind to the post that actually arrives at an SME: receipts,
-- delivery notes, quotes, orders, bank statements and authority documents were
-- all landing in 'egyeb', which makes the category useless for filtering.
--
-- The invoice is also split. In Hungarian accounting an előlegszámla,
-- helyesbítő számla and sztornó számla are not "just another invoice": the
-- sztornó cancels an earlier one and carries negative amounts, the helyesbítő
-- corrects one and references the original, and the előleg is deducted by the
-- final invoice. Filing them all as 'szamla' loses exactly the distinction a
-- könyvelő needs. 'dijbekero' already existed for the same reason — a payment
-- request is not an accounting document at all.
--
-- Every anchor below is a value that existed before this migration, so no
-- statement depends on a label added earlier in this same transaction.

alter type public.doc_kind add value if not exists 'elolegszamla' after 'szamla';
alter type public.doc_kind add value if not exists 'helyesbito_szamla' before 'dijbekero';
alter type public.doc_kind add value if not exists 'sztorno_szamla' before 'dijbekero';

alter type public.doc_kind add value if not exists 'nyugta' after 'dijbekero';
alter type public.doc_kind add value if not exists 'arajanlat' before 'szerzodes';
alter type public.doc_kind add value if not exists 'megrendeles' before 'szerzodes';

alter type public.doc_kind add value if not exists 'szallitolevel' after 'szerzodes';
alter type public.doc_kind add value if not exists 'banki_kivonat' after 'teljesites';
alter type public.doc_kind add value if not exists 'hatosagi' before 'nyilatkozat';

-- Resulting order (enum order drives nothing today, but keep it readable):
--   level, szamla, elolegszamla, helyesbito_szamla, sztorno_szamla,
--   dijbekero, nyugta, arajanlat, megrendeles, szerzodes, szallitolevel,
--   teljesites, banki_kivonat, hatosagi, nyilatkozat, egyeb
