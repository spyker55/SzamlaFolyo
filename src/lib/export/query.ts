import type { SupabaseClient } from "@supabase/supabase-js";
import { compareIktatoszamDesc } from "@/lib/iktatas/order";
import type { ExportRow } from "@/lib/export/csv";
import type { DateBasis } from "@/lib/export/period";

// What a bookkeeper is handed over: everything that reached the iktatokonyv
// in the period. Not the inbox — an irat that is still being checked has no
// iktatoszam and no confirmed amounts, so it is not evidence of anything yet.
const EXPORTED_STATUSES = ["iktatva", "ervenytelenitve"] as const;

// One handover cannot reasonably exceed this; the page says so when it bites.
export const MAX_EXPORT_ROWS = 2000;

export type ExportFile = {
  storagePath: string;
  originalFilename: string | null;
};

export type ExportItem = ExportRow & {
  id: string;
  file: ExportFile | null;
};

type Row = {
  id: string;
  iktatoszam: string | null;
  alszam: number | null;
  erkezett_at: string | null;
  issue_date: string | null;
  due_date: string | null;
  doc_kind: string | null;
  direction: string | null;
  irat_szama: string | null;
  net_amount: string | number | null;
  vat_amount: string | number | null;
  gross_amount: string | number | null;
  currency: string | null;
  fizetve_at: string | null;
  targy: string | null;
  processing_status: string;
  ervenytelenites_indoka: string | null;
  partner: { name: string; tax_number: string | null } | null;
  ugy: {
    foszam: number | null;
    ev: number | null;
    targy: string | null;
    irattari_jel: string | null;
    eloado: { full_name: string | null; email: string } | null;
  } | null;
  document_file: { storage_path: string; original_filename: string | null }[];
};

export type ExportQuery = {
  from: string;
  to: string;
  basis: DateBasis;
  // null means both directions; a bookkeeper usually wants both, but the
  // separation is one click away when they do not.
  direction: "bejovo" | "kimeno" | null;
};

// 'kelt' filters on the invoice's own date, which is what the VAT period is
// built from; 'erkezett' filters on when it reached us, which is what the
// iktatokonyv is built from. They are different questions and the caller has
// to say which one it is asking.
function dateColumn(basis: DateBasis): "erkezett_at" | "issue_date" {
  return basis === "kelt" ? "issue_date" : "erkezett_at";
}

export async function fetchExportItems(
  supabase: SupabaseClient,
  query: ExportQuery
): Promise<ExportItem[]> {
  let q = supabase
    .from("document")
    .select(
      `id, iktatoszam, alszam, erkezett_at, issue_date, due_date, doc_kind, direction,
       irat_szama, net_amount, vat_amount, gross_amount, currency, fizetve_at,
       targy, processing_status, ervenytelenites_indoka,
       partner:partner_id (name, tax_number),
       ugy:ugy_id (foszam, ev, targy, irattari_jel,
         eloado:eloado_user_id (full_name, email)),
       document_file (storage_path, original_filename)`
    )
    .in("processing_status", [...EXPORTED_STATUSES])
    .is("deleted_at", null)
    .gte(dateColumn(query.basis), query.from)
    .lte(dateColumn(query.basis), query.to)
    .limit(MAX_EXPORT_ROWS);

  if (query.direction) q = q.eq("direction", query.direction);

  const { data, error } = await q;
  if (error) throw new Error(error.message);

  const rows = (data ?? []) as unknown as Row[];

  // Ascending: a handover reads like the book itself, oldest first.
  const sorted = [...rows].sort((a, b) =>
    compareIktatoszamDesc(
      { ev: b.ugy?.ev ?? null, foszam: b.ugy?.foszam ?? null, alszam: b.alszam },
      { ev: a.ugy?.ev ?? null, foszam: a.ugy?.foszam ?? null, alszam: a.alszam }
    )
  );

  return sorted.map((d) => {
    const file = d.document_file?.[0] ?? null;
    return {
      id: d.id,
      iktatoszam: d.iktatoszam,
      erkezettAt: d.erkezett_at,
      issueDate: d.issue_date,
      dueDate: d.due_date,
      docKind: d.doc_kind,
      direction: d.direction,
      partnerName: d.partner?.name ?? null,
      partnerTaxNumber: d.partner?.tax_number ?? null,
      iratSzama: d.irat_szama,
      // PostgREST returns NUMERIC as a string.
      netAmount: toNumber(d.net_amount),
      vatAmount: toNumber(d.vat_amount),
      grossAmount: toNumber(d.gross_amount),
      currency: d.currency,
      fizetveAt: d.fizetve_at,
      targy: d.targy,
      ugyTargy: d.ugy?.targy ?? null,
      eloado: d.ugy?.eloado?.full_name ?? d.ugy?.eloado?.email ?? null,
      irattariJel: d.ugy?.irattari_jel ?? null,
      ervenytelen: d.processing_status === "ervenytelenitve",
      ervenytelenitesIndoka: d.ervenytelenites_indoka,
      fileName: file?.original_filename ?? null,
      file: file ? { storagePath: file.storage_path, originalFilename: file.original_filename } : null,
    };
  });
}

function toNumber(v: string | number | null): number | null {
  if (v === null) return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}
