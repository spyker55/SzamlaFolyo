// A bookkeeper's handover is always for a period, and the period has to be
// stated in the file itself — a CSV called "export.csv" on someone's desktop
// three months later is worthless.

export type DateBasis = "erkezett" | "kelt";

export const DATE_BASIS_LABEL: Record<DateBasis, string> = {
  erkezett: "Beérkezés dátuma",
  kelt: "Irat kelte",
};

export function isDateBasis(value: string | null | undefined): value is DateBasis {
  return value === "erkezett" || value === "kelt";
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const ISO_MONTH = /^\d{4}-\d{2}$/;

export function isIsoDate(value: string): boolean {
  if (!ISO_DATE.test(value)) return false;
  // Rejects 2026-02-31: Date normalizes it, so a round-trip catches it.
  const d = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value;
}

export type MonthRange = { from: string; to: string };

// Inclusive on both ends, so callers use gte/lte and nothing falls between
// two months.
export function monthRange(month: string): MonthRange | null {
  if (!ISO_MONTH.test(month)) return null;
  const year = Number(month.slice(0, 4));
  const m = Number(month.slice(5, 7));
  if (m < 1 || m > 12) return null;
  // Day 0 of the next month is the last day of this one.
  const last = new Date(Date.UTC(year, m, 0)).getUTCDate();
  return { from: `${month}-01`, to: `${month}-${String(last).padStart(2, "0")}` };
}

const MONTH_NAME = [
  "január", "február", "március", "április", "május", "június",
  "július", "augusztus", "szeptember", "október", "november", "december",
];

export function monthLabel(month: string): string {
  if (!ISO_MONTH.test(month)) return month;
  return `${month.slice(0, 4)}. ${MONTH_NAME[Number(month.slice(5, 7)) - 1]}`;
}

// The company's own month, not the server's.
export function currentMonthInBudapest(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/Budapest",
    year: "numeric",
    month: "2-digit",
  })
    .format(now)
    .slice(0, 7);
}

// Recent months, newest first — a bookkeeper hands over last month far more
// often than this one.
export function recentMonths(count: number, now: Date = new Date()): string[] {
  const current = currentMonthInBudapest(now);
  const year = Number(current.slice(0, 4));
  const month = Number(current.slice(5, 7));
  const out: string[] = [];
  for (let i = 0; i < count; i++) {
    const d = new Date(Date.UTC(year, month - 1 - i, 1));
    out.push(`${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`);
  }
  return out;
}

// Safe for a Content-Disposition filename and for every filesystem the
// bookkeeper might save it on.
export function exportBaseName(companyName: string, from: string, to: string): string {
  const slug = companyName
    .toLowerCase()
    // NFD splits "é" into "e" + a combining accent; the accent has to be
    // dropped before the filter, or every accented letter would leave a
    // separator behind ("napfe-ny").
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40);
  const period = from.slice(0, 7) === to.slice(0, 7) ? from.slice(0, 7) : `${from}_${to}`;
  return `${slug || "iratok"}_${period}`;
}
