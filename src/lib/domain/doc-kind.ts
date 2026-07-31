// The irat type vocabulary, in one place. The Postgres enum public.doc_kind is
// the authority (see 20260730000011_doc_kind_expand.sql); this list mirrors it
// in the order the enum declares, and doc-kind.test.ts fails if a value ever
// ships without a Hungarian label.
//
// Before this module the list lived in three places — the extraction schema,
// the review screen and the iktatókönyv filter — and adding a value meant
// remembering all three.

export const DOC_KINDS = [
  "level",
  "szamla",
  "elolegszamla",
  "helyesbito_szamla",
  "sztorno_szamla",
  "dijbekero",
  "nyugta",
  "arajanlat",
  "megrendeles",
  "szerzodes",
  "szallitolevel",
  "teljesites",
  "banki_kivonat",
  "hatosagi",
  "nyilatkozat",
  "egyeb",
] as const;

export type DocKind = (typeof DOC_KINDS)[number];

export const DOC_KIND_LABEL: Record<DocKind, string> = {
  level: "Levél",
  szamla: "Számla",
  elolegszamla: "Előlegszámla",
  helyesbito_szamla: "Helyesbítő számla",
  sztorno_szamla: "Sztornó számla",
  dijbekero: "Díjbekérő",
  nyugta: "Nyugta",
  arajanlat: "Árajánlat",
  megrendeles: "Megrendelés",
  szerzodes: "Szerződés",
  szallitolevel: "Szállítólevél",
  teljesites: "Teljesítés",
  banki_kivonat: "Bankkivonat",
  hatosagi: "Hatósági irat",
  nyilatkozat: "Nyilatkozat",
  egyeb: "Egyéb",
};

export const DOC_KIND_OPTIONS: { value: DocKind; label: string }[] = DOC_KINDS.map((value) => ({
  value,
  label: DOC_KIND_LABEL[value],
}));

// An unknown value is shown as-is rather than hidden behind "Egyéb": if the
// database holds a label this build does not know about, the user should see
// the real thing instead of a quiet mislabelling.
export function docKindLabel(value: string | null | undefined): string {
  if (!value) return "—";
  return DOC_KIND_LABEL[value as DocKind] ?? value;
}
