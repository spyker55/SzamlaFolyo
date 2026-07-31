// Bank account numbers, for the partners you actually transfer money to.
//
// Two shapes appear on the iratok in this register: the Hungarian GIRO number
// (2 or 3 blocks of 8 digits) and an IBAN, because not every supplier is
// Hungarian — Websupport s. r. o. is Slovak.
//
// The IBAN is checked properly: the mod-97 rule is part of ISO 13616 and a
// number that fails it is wrong, full stop.
//
// The Hungarian GIRO number's per-block check digit is verified too, but only
// as a warning the user can dismiss by saving anyway. The weighting was
// confirmed on the adószám of three real invoices in this register and on
// both non-trivial blocks of a published Hungarian IBAN, which is enough to
// point at a typo and not enough to refuse someone's money transfer.

export type BankAccountKind = "hu" | "iban" | "ismeretlen";

export function normalizeBankAccount(raw: string | null | undefined): string {
  if (!raw) return "";
  return raw.replace(/[\s-]/g, "").toUpperCase();
}

export function bankAccountKind(raw: string | null | undefined): BankAccountKind {
  const s = normalizeBankAccount(raw);
  if (s === "") return "ismeretlen";
  if (/^[0-9]{16}$/.test(s) || /^[0-9]{24}$/.test(s)) return "hu";
  if (/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/.test(s)) return "iban";
  return "ismeretlen";
}

// ISO 13616: move the first four characters to the end, replace each letter
// with its position in the alphabet plus 9, and the whole number read as an
// integer must be 1 modulo 97. Computed digit by digit because the value is
// far past Number.MAX_SAFE_INTEGER.
export function isValidIban(raw: string | null | undefined): boolean {
  const s = normalizeBankAccount(raw);
  if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/.test(s)) return false;

  const rearranged = s.slice(4) + s.slice(0, 4);
  let remainder = 0;
  for (const ch of rearranged) {
    const value = ch >= "0" && ch <= "9" ? ch : String(ch.charCodeAt(0) - 55);
    for (const digit of value) {
      remainder = (remainder * 10 + Number(digit)) % 97;
    }
  }
  return remainder === 1;
}

// Each 8-digit block carries its check digit in the last position, weighted
// 9-7-3-1-9-7-3 over the first seven — the same scheme the adószám uses.
const GIRO_WEIGHTS = [9, 7, 3, 1, 9, 7, 3];

export function huBlocksLookRight(raw: string | null | undefined): boolean {
  const s = normalizeBankAccount(raw);
  if (bankAccountKind(s) !== "hu") return false;
  return (s.match(/.{8}/g) ?? []).every((block) => {
    let sum = 0;
    for (let i = 0; i < 7; i++) sum += Number(block[i]) * GIRO_WEIGHTS[i];
    return (10 - (sum % 10)) % 10 === Number(block[7]);
  });
}

// Worth saying out loud, never worth blocking on. Returns null when there is
// nothing to warn about.
export function bankAccountWarning(raw: string | null | undefined): string | null {
  if (bankAccountKind(raw) !== "hu") return null;
  if (huBlocksLookRight(raw)) return null;
  return "A bankszámlaszám ellenőrző számjegye nem stimmel — érdemes újranézni.";
}

export type BankAccountCheck = { ok: true } | { ok: false; message: string };

export function checkBankAccount(raw: string): BankAccountCheck {
  const trimmed = raw.trim();
  if (trimmed === "") return { ok: true };

  switch (bankAccountKind(trimmed)) {
    case "hu":
      return { ok: true };
    case "iban":
      return isValidIban(trimmed)
        ? { ok: true }
        : { ok: false, message: "Az IBAN ellenőrző száma nem stimmel." };
    default:
      return {
        ok: false,
        message: "A bankszámlaszám 16 vagy 24 számjegy, vagy egy IBAN.",
      };
  }
}

// 16 digits are written as two blocks and 24 as three; an IBAN is grouped in
// fours, the way banks print it. Anything else is left exactly as typed.
export function formatBankAccount(raw: string | null | undefined): string {
  const s = normalizeBankAccount(raw);
  switch (bankAccountKind(s)) {
    case "hu":
      return (s.match(/.{8}/g) ?? [s]).join("-");
    case "iban":
      return (s.match(/.{1,4}/g) ?? [s]).join(" ");
    default:
      return raw?.trim() ?? "";
  }
}
