// What makes two partner rows the same company.
//
// Both functions here mirror something the database already does, and the
// mirror has to be exact — the screen decides what to offer, the database
// decides what is allowed, and the two disagreeing means offering a button
// that fails. tests/partner-identity.test.ts rebuilds both from the migrations.

// app.normalize_company_name() folds exactly these nine letters with
// translate() and then drops everything that is not [a-z0-9]. It deliberately
// does NOT use unaccent(), so a Czech "č" is removed rather than folded to
// "c" — normalizing here with NFD instead would quietly disagree.
const HU_ACCENTED = "áéíóöőúüű";
const HU_PLAIN = "aeiooouuu";

export function normalizePartnerName(raw: string | null | undefined): string {
  if (!raw) return "";
  let folded = "";
  for (const ch of raw.toLowerCase()) {
    const at = HU_ACCENTED.indexOf(ch);
    folded += at >= 0 ? HU_PLAIN[at] : ch;
  }
  return folded.replace(/[^a-z0-9]+/g, "");
}

// A Hungarian adószám is törzsszám (8) + ÁFA-kód (1) + megyekód (2). The ÁFA
// code changes over a company's life — an EVA exit, joining a VAT group — and
// the megyekód follows the seat, but the törzsszám never moves. So the
// törzsszám alone answers "is this the same taxpayer".
//
// Anything that is not an 11-digit Hungarian number is compared whole, which
// is right for EU VAT numbers and for the free text that occasionally lands
// in the field. Mirrors app.tax_number_core().
export function taxNumberCore(raw: string | null | undefined): string | null {
  if (raw === null || raw === undefined) return null;
  const alnum = raw.toUpperCase().replace(/[^0-9A-Z]/g, "");
  if (/^[0-9]{11}$/.test(alnum)) return alnum.slice(0, 8);
  return alnum === "" ? null : alnum;
}

export function isHuTaxNumber(raw: string | null | undefined): boolean {
  if (!raw) return false;
  return /^[0-9]{11}$/.test(raw.replace(/[^0-9A-Za-z]/g, ""));
}

// The törzsszám carries a check digit in its 8th position, weighted
// 9-7-3-1-9-7-3 over the first seven. Verified against the adószám on three
// real invoices in this register (Nethely, Kovács Épületgépészet, Delivery
// Hero) before it was allowed to reject anything.
const TAX_WEIGHTS = [9, 7, 3, 1, 9, 7, 3];

// Takes the törzsszám on its own as well: the check digit lives inside those
// eight digits and does not need the rest of the number to be present.
export function isValidHuTaxNumber(raw: string | null | undefined): boolean {
  if (!raw) return false;
  const digits = raw.replace(/[^0-9]/g, "");
  if (digits.length !== 8 && digits.length !== 11) return false;
  if (/[A-Za-z]/.test(raw)) return false;
  let sum = 0;
  for (let i = 0; i < 7; i++) sum += Number(digits[i]) * TAX_WEIGHTS[i];
  return (10 - (sum % 10)) % 10 === Number(digits[7]);
}

// "12345678912" -> "12345678-9-12". Anything that is not an 11-digit
// Hungarian number is handed back untouched: an EU VAT number has its own
// shape and inventing dashes in it would be wrong.
export function formatTaxNumber(raw: string | null | undefined): string {
  if (!raw) return "";
  const trimmed = raw.trim();
  if (!isHuTaxNumber(trimmed)) return trimmed;
  const d = trimmed.replace(/[^0-9]/g, "");
  return `${d.slice(0, 8)}-${d.slice(8, 9)}-${d.slice(9, 11)}`;
}

export type TaxNumberCheck = { ok: true } | { ok: false; message: string };

// Refuses only what is certainly wrong. A tax number that is neither 11 digits
// nor a plausible EU VAT number is still accepted with no complaint — this
// field also holds foreign registration numbers, and a register that refuses
// to record what is printed on the irat is worse than one that records it.
export function checkTaxNumber(raw: string): TaxNumberCheck {
  const trimmed = raw.trim();
  if (trimmed === "") return { ok: true };
  const digits = trimmed.replace(/[^0-9]/g, "");
  const hasLetters = /[A-Za-z]/.test(trimmed);

  if (!hasLetters && digits.length !== 11 && digits.length !== 8) {
    return {
      ok: false,
      message: "A magyar adószám 11 számjegy (12345678-1-23), a törzsszám 8.",
    };
  }
  if (!hasLetters && !isValidHuTaxNumber(trimmed)) {
    return { ok: false, message: "Az adószám ellenőrző számjegye nem stimmel." };
  }
  return { ok: true };
}
