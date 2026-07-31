import { docKindLabel } from "@/lib/domain/doc-kind";

// The file is opened in Hungarian Excel, so the separator is a semicolon and
// the decimal mark is a comma — with a dot Excel would read 16975.00 as text
// and every sum in the bookkeeper's sheet would silently be wrong.
export const CSV_SEPARATOR = ";";

// Excel only reads UTF-8 CSV correctly with a BOM. Without it "Websupport
// s. r. o." arrives as mojibake and the accented partner names are unusable.
export const UTF8_BOM = "﻿";

export type ExportRow = {
  iktatoszam: string | null;
  erkezettAt: string | null;
  issueDate: string | null;
  dueDate: string | null;
  docKind: string | null;
  direction: string | null;
  partnerName: string | null;
  partnerTaxNumber: string | null;
  iratSzama: string | null;
  netAmount: number | null;
  vatAmount: number | null;
  grossAmount: number | null;
  currency: string | null;
  fizetveAt: string | null;
  targy: string | null;
  ugyTargy: string | null;
  eloado: string | null;
  irattariJel: string | null;
  ervenytelen: boolean;
  ervenytelenitesIndoka: string | null;
  fileName: string | null;
};

type Column = {
  header: string;
  // A number column is written unquoted and unguarded so Excel parses it;
  // a text column is quoted and formula-guarded.
  value: (row: ExportRow) => string | number | null;
  numeric?: boolean;
};

const DIRECTION_LABEL: Record<string, string> = {
  bejovo: "Bejövő",
  kimeno: "Kimenő",
};

export const COLUMNS: Column[] = [
  { header: "Iktatószám", value: (r) => r.iktatoszam },
  { header: "Beérkezés", value: (r) => r.erkezettAt },
  { header: "Irat típusa", value: (r) => (r.docKind ? docKindLabel(r.docKind) : null) },
  { header: "Irány", value: (r) => (r.direction ? DIRECTION_LABEL[r.direction] ?? r.direction : null) },
  { header: "Partner", value: (r) => r.partnerName },
  { header: "Partner adószáma", value: (r) => r.partnerTaxNumber },
  { header: "Bizonylatszám", value: (r) => r.iratSzama },
  { header: "Kelt", value: (r) => r.issueDate },
  { header: "Fizetési határidő", value: (r) => r.dueDate },
  { header: "Nettó", value: (r) => r.netAmount, numeric: true },
  { header: "ÁFA", value: (r) => r.vatAmount, numeric: true },
  { header: "Bruttó", value: (r) => r.grossAmount, numeric: true },
  { header: "Pénznem", value: (r) => r.currency },
  { header: "Kifizetve", value: (r) => r.fizetveAt },
  { header: "Tárgy", value: (r) => r.targy },
  { header: "Ügy tárgya", value: (r) => r.ugyTargy },
  { header: "Előadó", value: (r) => r.eloado },
  { header: "Irattári jel", value: (r) => r.irattariJel },
  { header: "Könyvelendő", value: (r) => (isAccountingDocument(r) ? "igen" : "nem") },
  { header: "Státusz", value: (r) => (r.ervenytelen ? "ÉRVÉNYTELENÍTVE" : "Iktatva") },
  { header: "Érvénytelenítés indoka", value: (r) => r.ervenytelenitesIndoka },
  { header: "Fájl", value: (r) => r.fileName },
];

// Up to four decimals because that is the storage scale, but never fewer than
// two: a bookkeeper reading a column of amounts should not have to wonder
// whether 16975 is rounded.
export function csvNumber(value: number | null): string {
  if (value === null || !Number.isFinite(value)) return "";
  const fixed = value.toFixed(4).replace(/(\.\d\d)(\d*?)0+$/, "$1$2");
  return fixed.replace(".", ",");
}

// A partner name comes from a model reading a PDF, so it is untrusted text
// that will land in a spreadsheet. A cell starting with =, +, - or @ is a
// formula to Excel, and =HYPERLINK(...) or =cmd|... in a shared file is a
// real attack, not a theoretical one. The apostrophe forces it to text and
// is not displayed by Excel.
export function csvText(value: string | null | undefined): string {
  if (value === null || value === undefined || value === "") return "";
  let s = String(value).replace(/\r\n?/g, "\n");
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return `"${s.replace(/"/g, '""')}"`;
}

export function toCsv(rows: ExportRow[]): string {
  const lines: string[] = [];
  lines.push(COLUMNS.map((c) => csvText(c.header)).join(CSV_SEPARATOR));

  for (const row of rows) {
    const cells = COLUMNS.map((c) => {
      const v = c.value(row);
      if (c.numeric) return csvNumber(v === null || v === "" ? null : Number(v));
      return csvText(v === null ? null : String(v));
    });
    lines.push(cells.join(CSV_SEPARATOR));
  }

  // CRLF is what Excel expects from a CSV; the trailing one closes the last
  // record so a strict reader does not report a truncated file.
  return UTF8_BOM + lines.join("\r\n") + "\r\n";
}

export type CurrencyTotal = { currency: string; net: number; vat: number; gross: number };

// Only these are számviteli bizonylat — what the bookkeeper actually books.
// A díjbekérő is a request for payment, not an accounting document: the
// invoice issued for it carries the same amount, so summing both would put
// the cost in the books twice. A szállítólevél and a szerződés carry no
// bookable amount at all. A nyugta does, so it belongs here.
export const ACCOUNTING_KINDS: readonly string[] = [
  "szamla",
  "elolegszamla",
  "helyesbito_szamla",
  "sztorno_szamla",
  "nyugta",
];

export function isAccountingDocument(row: ExportRow): boolean {
  return row.docKind !== null && ACCOUNTING_KINDS.includes(row.docKind);
}

// Currencies are never mixed, and a withdrawn irat is not a cost — it is in
// the CSV for the audit trail, but it must not move a total.
export function accountingTotals(rows: ExportRow[]): CurrencyTotal[] {
  const map = new Map<string, CurrencyTotal>();
  for (const r of rows) {
    if (r.ervenytelen) continue;
    if (!isAccountingDocument(r)) continue;
    // An irat with no amount at all would otherwise open a currency group of
    // pure zeroes.
    if (r.netAmount === null && r.vatAmount === null && r.grossAmount === null) continue;
    const currency = r.currency ?? "—";
    const t = map.get(currency) ?? { currency, net: 0, vat: 0, gross: 0 };
    t.net += r.netAmount ?? 0;
    t.vat += r.vatAmount ?? 0;
    t.gross += r.grossAmount ?? 0;
    map.set(currency, t);
  }
  return [...map.values()]
    .map((t) => ({
      currency: t.currency,
      // Repeated addition of NUMERIC values read as floats drifts; the
      // storage scale is 4 decimals, so round back to it.
      net: Number(t.net.toFixed(4)),
      vat: Number(t.vat.toFixed(4)),
      gross: Number(t.gross.toFixed(4)),
    }))
    .sort((a, b) => a.currency.localeCompare(b.currency, "hu"));
}
