// Két lista összevetése: mit tud a NAV, és mi van iktatva.
//
// This is the whole point of the integration and it is deliberately pure —
// no database, no network, no clock. Everything it decides can be checked in
// a test, because what it decides is an accusation: "erről a számláról tud a
// NAV, de nálad nincs iktatva" is either useful or an alarm that trains people
// to ignore alarms.
//
// Which is why most of the code below is about what *not* to compare. NAV's
// list is not the register's list, and four honest differences between them
// would each produce a false alarm:
//
//   - a díjbekérő and a nyugta are not invoices and are never reported;
//   - an invoice from a supplier without a Hungarian tax number is not
//     reported to NAV by anyone;
//   - an invoice issued a day ago may not be reported yet — the supplier has
//     until the end of the next working day, and machine-issued invoices
//     arrive immediately while hand-written ones do not;
//   - a VAT group reports under the group's tax number while the invoice is
//     printed with the member's.
//
// Each of those is handled by name rather than by loosening the match, because
// a loose match hides the real finding instead of preventing a false one.

export type NavSide = {
  id: string;
  invoiceNumber: string;
  invoiceNumberKey: string;
  partnerTaxCore: string | null;
  partnerGroupTaxCore: string | null;
  partnerName: string | null;
  issueDate: string | null;
  currency: string | null;
  netAmount: number | null;
  vatAmount: number | null;
  invoiceOperation: string | null;
  invoiceCategory: string | null;
  insDate: string | null;
};

export type RegisterSide = {
  id: string;
  ugyId: string | null;
  iktatoszam: string | null;
  iratSzama: string | null;
  iratSzamaKey: string;
  partnerTaxCore: string | null;
  partnerName: string | null;
  docKind: string | null;
  issueDate: string | null;
  currency: string | null;
  grossAmount: number | null;
  ervenytelenitve: boolean;
};

export type KihagyasOk = "nem_szamla" | "ervenytelenitve" | "kulfoldi" | "nincs_szam";

export const KIHAGYAS_OK_LABEL: Record<KihagyasOk, string> = {
  nem_szamla: "Nem számla (a NAV-hoz nem is kerül be)",
  ervenytelenitve: "Érvénytelenített irat",
  kulfoldi: "Nincs magyar adószáma a partnernek",
  nincs_szam: "Nincs rögzítve számlaszám",
};

export type Parositas = { nav: NavSide; irat: RegisterSide };
export type Kihagyva = { irat: RegisterSide; ok: KihagyasOk };

export type Egyeztetes = {
  /** Same invoice number, and the tax numbers do not contradict each other. */
  egyezik: Parositas[];
  /** Same partner, date and amount, different number — almost always a typo in one of them. */
  valoszinu: Parositas[];
  /** NAV has it and the register does not. This is what the feature is for. */
  hianyzik: NavSide[];
  /** The register has it and NAV does not. */
  nincsNavnal: RegisterSide[];
  /** Too recently issued to expect a report yet. */
  friss: RegisterSide[];
  /** Not comparable at all, with the reason named. */
  kihagyva: Kihagyva[];
};

// Only these ever reach NAV. A díjbekérő is a payment request, not an invoice;
// a nyugta is an invoice the customer was never named on. Filing them is
// right; expecting NAV to know them is not.
const SZAMLA_KINDS = new Set(["szamla", "elolegszamla", "helyesbito_szamla", "sztorno_szamla"]);

// The supplier has until the end of the next working day to report a
// hand-issued invoice, and a weekend and a bank holiday can stretch that. Five
// calendar days is the smallest window that does not cry wolf every Monday.
export const JELENTESI_HALADEK_NAP = 5;

function isHuTaxCore(core: string | null): boolean {
  return core !== null && /^[0-9]{8}$/.test(core);
}

function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000);
}

// NAV's digest reports net and VAT separately. For a NORMAL invoice their sum
// is the gross the register holds; for a SIMPLIFIED one the breakdown does not
// exist in the same shape, so no gross is claimed rather than a wrong one
// computed. The amount is only ever used to *suggest* a match, never to reject
// one, so declining to guess costs a suggestion and nothing else.
export function navGross(row: NavSide): number | null {
  if (row.invoiceCategory !== null && row.invoiceCategory !== "NORMAL") return null;
  if (row.netAmount === null) return null;
  return row.netAmount + (row.vatAmount ?? 0);
}

type Group = {
  numberKey: string;
  taxCores: Set<string>;
  latest: NavSide;
  claimed: boolean;
};

function groupNavRows(rows: NavSide[]): Group[] {
  const byKey = new Map<string, Group>();

  for (const row of rows) {
    // One invoice, reported more than once — a correction of the report
    // itself, not a second invoice. The parties keep it together, the latest
    // submission speaks for it.
    const key = `${row.partnerTaxCore ?? row.partnerGroupTaxCore ?? ""}|${row.invoiceNumberKey}`;
    const existing = byKey.get(key);
    if (!existing) {
      const cores = new Set<string>();
      if (row.partnerTaxCore) cores.add(row.partnerTaxCore);
      if (row.partnerGroupTaxCore) cores.add(row.partnerGroupTaxCore);
      byKey.set(key, { numberKey: row.invoiceNumberKey, taxCores: cores, latest: row, claimed: false });
      continue;
    }
    if (row.partnerTaxCore) existing.taxCores.add(row.partnerTaxCore);
    if (row.partnerGroupTaxCore) existing.taxCores.add(row.partnerGroupTaxCore);
    if ((row.insDate ?? "") > (existing.latest.insDate ?? "")) existing.latest = row;
  }

  return [...byKey.values()];
}

// Unknown on either side is not a contradiction. An extraction that missed the
// supplier's tax number should still match on the invoice number; refusing
// would report a filed invoice as missing, which is the one error this must
// not make.
function taxCompatible(group: Group, irat: RegisterSide): boolean {
  if (!irat.partnerTaxCore || group.taxCores.size === 0) return true;
  return group.taxCores.has(irat.partnerTaxCore);
}

function sameAmount(a: number, b: number): boolean {
  return Math.abs(a - b) < 0.5;
}

export function egyeztet(args: {
  nav: NavSide[];
  register: RegisterSide[];
  today: string;
  haladekNap?: number;
}): Egyeztetes {
  const haladek = args.haladekNap ?? JELENTESI_HALADEK_NAP;
  const groups = groupNavRows(args.nav);

  const byNumber = new Map<string, Group[]>();
  for (const group of groups) {
    const list = byNumber.get(group.numberKey);
    if (list) list.push(group);
    else byNumber.set(group.numberKey, [group]);
  }

  const egyezik: Parositas[] = [];
  const valoszinu: Parositas[] = [];
  const kihagyva: Kihagyva[] = [];
  const maradek: RegisterSide[] = [];

  // Deterministic order: the same two lists must always produce the same
  // pairing, including which of two identically-numbered iratok claims a
  // NAV row.
  const register = [...args.register].sort((a, b) =>
    (a.iratSzamaKey || a.id).localeCompare(b.iratSzamaKey || b.id) || a.id.localeCompare(b.id)
  );

  for (const irat of register) {
    const ok = kihagyasOk(irat);
    if (ok) {
      kihagyva.push({ irat, ok });
      continue;
    }

    const candidates = (byNumber.get(irat.iratSzamaKey) ?? []).filter(
      (g) => !g.claimed && taxCompatible(g, irat)
    );

    // Prefer a candidate whose tax number actually matches; only fall back to
    // the merely-not-contradicting one when there is no better answer.
    const exact = candidates.filter(
      (g) => irat.partnerTaxCore !== null && g.taxCores.has(irat.partnerTaxCore)
    );
    const chosen = exact[0] ?? candidates[0];

    if (chosen) {
      chosen.claimed = true;
      egyezik.push({ nav: chosen.latest, irat });
      continue;
    }
    maradek.push(irat);
  }

  const nincsNavnal: RegisterSide[] = [];
  const friss: RegisterSide[] = [];

  for (const irat of maradek) {
    const probable = groups.find((g) => {
      if (g.claimed) return false;
      if (!taxCompatible(g, irat)) return false;
      // Without a tax number on at least one side, "same day, same amount" is
      // a coincidence waiting to happen.
      if (g.taxCores.size === 0 || irat.partnerTaxCore === null) return false;
      if (g.latest.issueDate === null || irat.issueDate === null) return false;
      if (g.latest.issueDate !== irat.issueDate) return false;
      if (g.latest.currency && irat.currency && g.latest.currency !== irat.currency) return false;
      const gross = navGross(g.latest);
      if (gross === null || irat.grossAmount === null) return false;
      return sameAmount(gross, irat.grossAmount);
    });

    if (probable) {
      probable.claimed = true;
      valoszinu.push({ nav: probable.latest, irat });
      continue;
    }

    if (irat.issueDate !== null && daysBetween(irat.issueDate, args.today) < haladek) {
      friss.push(irat);
    } else {
      nincsNavnal.push(irat);
    }
  }

  const hianyzik = groups
    .filter((g) => !g.claimed)
    .map((g) => g.latest)
    .sort((a, b) => (b.issueDate ?? "").localeCompare(a.issueDate ?? ""));

  return { egyezik, valoszinu, hianyzik, nincsNavnal, friss, kihagyva };
}

export function kihagyasOk(irat: RegisterSide): KihagyasOk | null {
  if (irat.ervenytelenitve) return "ervenytelenitve";
  if (!irat.docKind || !SZAMLA_KINDS.has(irat.docKind)) return "nem_szamla";
  if (!isHuTaxCore(irat.partnerTaxCore)) return "kulfoldi";
  if (irat.iratSzamaKey === "") return "nincs_szam";
  return null;
}

export function budapestToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/Budapest",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(now);
}
