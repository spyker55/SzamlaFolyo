import type { ExtractionResult } from "./schema";

// The model's self-reported confidence is poorly calibrated — it is confident
// when wrong. Deterministic validators override it downward where a checkable
// signal fails. Both signals are stored separately so their calibration can be
// measured against field_correction data later.

const ISO_CURRENCIES = new Set([
  "HUF", "EUR", "USD", "GBP", "CHF", "CZK", "PLN", "RON", "SEK", "NOK", "DKK", "JPY", "CNY",
]);

// Hungarian tax number: 8-digit base + CDV check digit + VAT code + county code.
export function isValidHungarianTaxNumber(raw: string): boolean {
  const digits = raw.replace(/\D/g, "");
  if (digits.length !== 11) return false;
  const base = digits.slice(0, 8).split("").map(Number);
  const weights = [9, 7, 3, 1, 9, 7, 3];
  const sum = weights.reduce((acc, w, i) => acc + w * base[i], 0);
  const check = (10 - (sum % 10)) % 10;
  if (check !== base[7]) return false;
  const vatCode = digits[8];
  return ["1", "2", "3", "4", "5"].includes(vatCode);
}

function isPlausibleDate(value: string | null): boolean | null {
  if (value === null) return null;
  const d = new Date(value + "T00:00:00Z");
  if (Number.isNaN(d.getTime())) return false;
  const year = d.getUTCFullYear();
  return year >= 2000 && year <= 2100;
}

export type ValidatorResults = Partial<Record<string, boolean>>;

export function runValidators(parsed: ExtractionResult): ValidatorResults {
  const results: ValidatorResults = {};

  if (parsed.partner_tax_number !== null) {
    results.partner_tax_number = isValidHungarianTaxNumber(parsed.partner_tax_number);
  }

  for (const field of ["erkezett_at", "issue_date", "due_date"] as const) {
    const plausible = isPlausibleDate(parsed[field]);
    if (plausible !== null) results[field] = plausible;
  }

  if (
    results.issue_date !== false &&
    results.due_date !== false &&
    parsed.issue_date !== null &&
    parsed.due_date !== null &&
    parsed.due_date < parsed.issue_date
  ) {
    results.due_date = false;
  }

  if (parsed.currency !== null) {
    results.currency = ISO_CURRENCIES.has(parsed.currency.toUpperCase());
  }

  // net + vat = gross, within 1 unit rounding tolerance. Fordított adózás /
  // alanyi adómentes (vat = 0, net = gross) passes this by construction.
  if (
    parsed.net_amount !== null &&
    parsed.vat_amount !== null &&
    parsed.gross_amount !== null
  ) {
    const ok = Math.abs(parsed.net_amount + parsed.vat_amount - parsed.gross_amount) <= 1;
    results.net_amount = ok;
    results.vat_amount = ok;
    results.gross_amount = ok;
  }

  return results;
}

export type FieldConfidence = {
  model: Record<string, number>;
  validators: ValidatorResults;
  combined: Record<string, number>;
};

const FAILED_VALIDATOR_CEILING = 0.3;

export function combineConfidence(
  modelConfidence: Record<string, number>,
  validators: ValidatorResults
): FieldConfidence {
  const combined: Record<string, number> = { ...modelConfidence };
  for (const [field, ok] of Object.entries(validators)) {
    if (ok === false) {
      combined[field] = Math.min(combined[field] ?? 1, FAILED_VALIDATOR_CEILING);
    }
  }
  return { model: modelConfidence, validators, combined };
}

export const REVIEW_THRESHOLD = 0.85;
