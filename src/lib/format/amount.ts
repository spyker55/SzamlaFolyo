// Money is NUMERIC(18,4) in the database and the UI is Hungarian, so amounts
// are displayed as "1 612 900,25" and parsed back tolerantly. Parsing never
// silently returns "no amount" for text the user actually typed — an
// unreadable field is reported as such so the caller can refuse to submit
// instead of storing a NULL the user never asked for.

// Storage scale: NUMERIC(18,4). Rounding here keeps what the user sees in
// agreement with what the database will hold.
const DECIMALS = 4;

// Hungarian orthography leaves four-digit numbers unbroken (1207) and groups
// from five digits up (12 345); hu-HU ICU data already does exactly that.
const FORMATTER = new Intl.NumberFormat("hu-HU", {
  minimumFractionDigits: 0,
  maximumFractionDigits: DECIMALS,
});

export type ParsedAmount =
  // value === null means the field was empty, which is a valid "no amount".
  | { ok: true; value: number | null }
  | { ok: false };

export function parseAmountHu(raw: string): ParsedAmount {
  const trimmed = raw.trim();
  if (trimmed === "") return { ok: true, value: null };

  // Any whitespace is a grouping separator — including the non-breaking and
  // narrow no-break spaces that Intl itself emits.
  let s = trimmed.replace(/[\s  ]/g, "");

  let negative = false;
  if (s.startsWith("-")) {
    negative = true;
    s = s.slice(1);
  }
  if (s === "") return { ok: false };

  const commas = countOf(s, ",");
  const dots = countOf(s, ".");

  let decimalSep: string | null = null;
  let groupSep: string | null = null;

  if (commas > 0 && dots > 0) {
    // Both marks present, so the rightmost one is the decimal mark:
    // "1.612.900,25" is Hungarian, "1,612,900.25" is English.
    decimalSep = s.lastIndexOf(",") > s.lastIndexOf(".") ? "," : ".";
    groupSep = decimalSep === "," ? "." : ",";
  } else if (commas > 1) {
    groupSep = ",";
  } else if (commas === 1) {
    // The comma is the Hungarian decimal mark, always.
    decimalSep = ",";
  } else if (dots > 1) {
    groupSep = ".";
  } else if (dots === 1) {
    // A single dot is genuinely ambiguous. The dot is also the traditional
    // Hungarian thousands separator, so "100.000" is a hundred thousand while
    // "256.5" is a decimal. Read exactly three trailing digits as grouping,
    // except after a leading zero — nobody writes "0.500" for five hundred.
    decimalSep = /^[1-9]\d{0,2}\.\d{3}$/.test(s) ? null : ".";
    groupSep = decimalSep === null ? "." : null;
  }

  let intPart = s;
  let fracPart = "";

  if (decimalSep) {
    const at = s.lastIndexOf(decimalSep);
    intPart = s.slice(0, at);
    fracPart = s.slice(at + 1);
    if (!/^\d+$/.test(fracPart)) return { ok: false };
  }

  if (groupSep) {
    const groups = intPart.split(groupSep);
    // Grouping is only well-formed as 1-3 digits followed by groups of three.
    if (!/^\d{1,3}$/.test(groups[0])) return { ok: false };
    for (const g of groups.slice(1)) {
      if (!/^\d{3}$/.test(g)) return { ok: false };
    }
    intPart = groups.join("");
  } else if (intPart !== "" && !/^\d+$/.test(intPart)) {
    return { ok: false };
  }

  const normalized = `${intPart === "" ? "0" : intPart}${fracPart === "" ? "" : `.${fracPart}`}`;
  const n = Number(negative ? `-${normalized}` : normalized);
  if (!Number.isFinite(n)) return { ok: false };

  return { ok: true, value: Number(n.toFixed(DECIMALS)) };
}

// Formats for display. Text that cannot be read as a number is handed back
// untouched so a user's in-progress typing is never destroyed.
export function formatAmountHu(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return "";
  if (typeof value === "number") {
    return Number.isFinite(value) ? FORMATTER.format(value) : "";
  }
  const parsed = parseAmountHu(value);
  if (!parsed.ok) return value;
  if (parsed.value === null) return "";
  return FORMATTER.format(parsed.value);
}

function countOf(s: string, ch: string): number {
  let n = 0;
  for (const c of s) if (c === ch) n++;
  return n;
}
