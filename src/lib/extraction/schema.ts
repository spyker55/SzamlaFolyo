import { z } from "zod";
import { DOC_KINDS } from "@/lib/domain/doc-kind";

export const DIRECTIONS = ["bejovo", "kimeno", "belso"] as const;

// Re-exported so extraction callers keep one import, but the vocabulary itself
// lives in @/lib/domain/doc-kind next to its Hungarian labels.
export { DOC_KINDS };

export const EXTRACTED_FIELDS = [
  "partner_name",
  "partner_tax_number",
  "targy",
  "irat_szama",
  "erkezett_at",
  "issue_date",
  "due_date",
  "direction",
  "doc_kind",
  "melleklet_db",
  "net_amount",
  "vat_amount",
  "gross_amount",
  "currency",
] as const;

export type ExtractedField = (typeof EXTRACTED_FIELDS)[number];

const isoDate = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/)
  .nullable();

export const extractionResultSchema = z.object({
  partner_name: z.string().nullable(),
  partner_tax_number: z.string().nullable(),
  targy: z.string().nullable(),
  irat_szama: z.string().nullable(),
  erkezett_at: isoDate,
  issue_date: isoDate,
  due_date: isoDate,
  direction: z.enum(DIRECTIONS).nullable(),
  doc_kind: z.enum(DOC_KINDS).nullable(),
  melleklet_db: z.number().int().min(0).nullable(),
  net_amount: z.number().nullable(),
  vat_amount: z.number().nullable(),
  gross_amount: z.number().nullable(),
  currency: z.string().length(3).nullable(),
  tobb_irat_gyanu: z.boolean().default(false),
  confidence: z.record(z.string(), z.number().min(0).max(1)).default({}),
});

export type ExtractionResult = z.infer<typeof extractionResultSchema>;

// Tool input schema handed to the model — the structured output contract.
export const extractionToolSchema = {
  type: "object" as const,
  properties: {
    partner_name: {
      type: ["string", "null"],
      description: "A bekuldo/kiallito fel neve (cegnev vagy szemelynev)",
    },
    partner_tax_number: {
      type: ["string", "null"],
      description: "Magyar adoszam 12345678-1-42 formaban, ha szerepel",
    },
    targy: {
      type: ["string", "null"],
      description: "Az irat targya roviden, magyarul (pl. 'Villanyszereles - 2026. junius')",
    },
    irat_szama: {
      type: ["string", "null"],
      description: "Az irat sajat azonositoja: szamlasorszam, szerzodesszam, hivatkozasi szam",
    },
    erkezett_at: {
      type: ["string", "null"],
      description: "CSAK akkor toltsd ki, ha lathato erkezteto belyegzo van az iraton (YYYY-MM-DD)",
    },
    issue_date: { type: ["string", "null"], description: "Kelt / kiallitas datuma (YYYY-MM-DD)" },
    due_date: {
      type: ["string", "null"],
      description: "Fizetesi vagy ugyintezesi hatarido (YYYY-MM-DD)",
    },
    direction: {
      type: ["string", "null"],
      enum: [...DIRECTIONS, null],
      description: "bejovo: a ceghez erkezett; kimeno: a ceg kuldi; belso: cegen beluli",
    },
    doc_kind: {
      type: ["string", "null"],
      enum: [...DOC_KINDS, null],
      description:
        "Az irat fajtaja. szamla: szamviteli bizonylat sorszammal es AFA-bontassal; " +
        "elolegszamla: elolegrol kiallitott szamla; helyesbito_szamla: korabbi szamlat modosit, " +
        "hivatkozik az eredeti sorszamara; sztorno_szamla: korabbi szamlat ervenytelenit, " +
        "az osszegek negativak; dijbekero: fizetesi keres, ami NEM szamla (proforma, elolegbekero); " +
        "nyugta: kiskereskedelmi nyugta, nincs rajta vevo adoszama; szallitolevel: aru atadas-atvetel, " +
        "jellemzoen osszeg nelkul; arajanlat, megrendeles, szerzodes, teljesites (teljesitesigazolas); " +
        "banki_kivonat: bankszamlakivonat; hatosagi: NAV, onkormanyzat, birosag, hatosagi hatarozat vagy felszolitas; " +
        "level: kiserolevel, ertesites; nyilatkozat; egyeb: csak ha tenyleg egyik sem illik ra",
    },
    melleklet_db: {
      type: ["integer", "null"],
      description: "Mellekletek szama, ha az irat emliti (pl. 'Mellekletek: 3 db'); kulonben null",
    },
    net_amount: { type: ["number", "null"], description: "Netto osszeg (csak szamla/dijbekero)" },
    vat_amount: { type: ["number", "null"], description: "AFA osszeg" },
    gross_amount: { type: ["number", "null"], description: "Brutto vegosszeg" },
    currency: { type: ["string", "null"], description: "ISO 4217 penznem, pl. HUF, EUR" },
    tobb_irat_gyanu: {
      type: "boolean",
      description: "true, ha a fajl lathatoan tobb kulonallo iratot tartalmaz",
    },
    confidence: {
      type: "object",
      description:
        "Mezonkenti konfidencia 0 es 1 kozott, minden fent kitoltott mezore (kulcs = mezonev)",
      additionalProperties: { type: "number" },
    },
  },
  required: ["confidence", "tobb_irat_gyanu"],
};
