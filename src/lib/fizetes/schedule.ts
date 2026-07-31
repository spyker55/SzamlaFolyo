// Fizetési naptár: what is still owed, and when.
//
// Everything here is a pure function over rows the caller already fetched, so
// the rules that decide what counts as a debt are testable without a database.

export type PayableDocument = {
  id: string;
  iktatoszam: string | null;
  ugyId: string | null;
  docKind: string | null;
  partnerName: string | null;
  targy: string | null;
  // YYYY-MM-DD, as the database stores a date.
  dueDate: string | null;
  grossAmount: number | null;
  currency: string | null;
  fizetveAt: string | null;
};

// A nyugta is deliberately absent: it is proof that something was already paid
// at the till, not a request for payment. A díjbekérő, on the other hand, is
// exactly a request for payment — it is not an accounting document, but it is
// the thing that actually has to be transferred.
const INVOICE_KINDS = ["szamla", "elolegszamla", "helyesbito_szamla", "sztorno_szamla"] as const;
export const PAYABLE_KINDS: readonly string[] = [...INVOICE_KINDS, "dijbekero"];

export type Bucket = "lejart" | "ma" | "het" | "honap" | "kesobb" | "nincs_hatarido";

export const BUCKET_ORDER: readonly Bucket[] = [
  "lejart",
  "ma",
  "het",
  "honap",
  "kesobb",
  "nincs_hatarido",
];

export const BUCKET_LABEL: Record<Bucket, string> = {
  lejart: "Lejárt",
  ma: "Ma esedékes",
  het: "7 napon belül",
  honap: "30 napon belül",
  kesobb: "Később",
  nincs_hatarido: "Nincs fizetési határidő",
};

export type ScheduleEntry = PayableDocument & { bucket: Bucket; daysLeft: number | null };
export type CurrencyTotal = { currency: string; amount: number };
export type ScheduleGroup = { bucket: Bucket; entries: ScheduleEntry[]; totals: CurrencyTotal[] };
export type Schedule = { groups: ScheduleGroup[]; totals: CurrencyTotal[]; count: number };

export function daysBetween(from: string, to: string): number {
  // Parsed as UTC midnight on both sides: a local-time parse would shift the
  // boundary by the offset and put a deadline in the wrong bucket near midnight.
  const a = Date.parse(`${from}T00:00:00Z`);
  const b = Date.parse(`${to}T00:00:00Z`);
  return Math.round((b - a) / 86_400_000);
}

export function bucketFor(dueDate: string | null, today: string): Bucket {
  if (!dueDate) return "nincs_hatarido";
  const days = daysBetween(today, dueDate);
  if (days < 0) return "lejart";
  if (days === 0) return "ma";
  if (days <= 7) return "het";
  if (days <= 30) return "honap";
  return "kesobb";
}

function isInvoiceLike(docKind: string | null): boolean {
  return INVOICE_KINDS.includes((docKind ?? "") as (typeof INVOICE_KINDS)[number]);
}

function amountKey(doc: PayableDocument): string | null {
  if (doc.grossAmount === null || !doc.currency) return null;
  return `${doc.grossAmount.toFixed(2)}|${doc.currency.trim().toUpperCase()}`;
}

// A díjbekérő and the invoice issued against it are one debt, not two. Once
// both are filed under the same ügy for the same amount, the invoice
// supersedes the request — counting both would overstate what is owed.
//
// Matching is exact and ügy-scoped on purpose. When the amounts differ, or the
// two were never filed together, nothing is suppressed and the user sees both:
// showing a debt twice is a visible error, hiding a real one is not.
export function withoutSupersededDijbekero(docs: PayableDocument[]): PayableDocument[] {
  const invoiced = new Map<string, Set<string>>();

  for (const doc of docs) {
    if (!doc.ugyId || !isInvoiceLike(doc.docKind)) continue;
    const key = amountKey(doc);
    if (!key) continue;
    const set = invoiced.get(doc.ugyId) ?? new Set<string>();
    set.add(key);
    invoiced.set(doc.ugyId, set);
  }

  return docs.filter((doc) => {
    if (doc.docKind !== "dijbekero" || !doc.ugyId) return true;
    const key = amountKey(doc);
    return key === null || !invoiced.get(doc.ugyId)?.has(key);
  });
}

export function buildSchedule(docs: PayableDocument[], today: string): Schedule {
  const payable = docs.filter(
    (d) =>
      PAYABLE_KINDS.includes(d.docKind ?? "") &&
      d.grossAmount !== null &&
      Boolean(d.currency)
  );

  // Suppression runs before the paid filter: an already-settled invoice still
  // supersedes its díjbekérő, and dropping it first would resurrect the
  // request as an outstanding debt.
  const outstanding = withoutSupersededDijbekero(payable).filter((d) => !d.fizetveAt);

  const entries: ScheduleEntry[] = outstanding.map((doc) => ({
    ...doc,
    bucket: bucketFor(doc.dueDate, today),
    daysLeft: doc.dueDate ? daysBetween(today, doc.dueDate) : null,
  }));

  const groups: ScheduleGroup[] = [];
  for (const bucket of BUCKET_ORDER) {
    const inBucket = entries
      .filter((e) => e.bucket === bucket)
      .sort(
        (a, b) =>
          (a.dueDate ?? "").localeCompare(b.dueDate ?? "") ||
          (a.iktatoszam ?? "").localeCompare(b.iktatoszam ?? "")
      );
    if (inBucket.length > 0) {
      groups.push({ bucket, entries: inBucket, totals: sumByCurrency(inBucket) });
    }
  }

  return { groups, totals: sumByCurrency(entries), count: entries.length };
}

// Amount and currency travel together, so sums never cross currencies.
export function sumByCurrency(entries: PayableDocument[]): CurrencyTotal[] {
  const byCurrency = new Map<string, number>();
  for (const e of entries) {
    if (e.grossAmount === null || !e.currency) continue;
    const currency = e.currency.trim().toUpperCase();
    byCurrency.set(currency, (byCurrency.get(currency) ?? 0) + e.grossAmount);
  }
  return [...byCurrency.entries()]
    .map(([currency, amount]) => ({ currency, amount: Number(amount.toFixed(4)) }))
    .sort((a, b) => a.currency.localeCompare(b.currency));
}
