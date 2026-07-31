// Which partner rows look like the same company — a suggestion, never an act.
//
// The unique indexes added in 20260731000019 already make the easy duplicates
// impossible: among live partners, one normalized name means one row when
// there is no tax number, and one tax number means one row when there is. So
// everything found here is a near-match, and near-matches are exactly the
// cases where a human has to decide.
//
// The legal form is compared, not stripped. 20260730000013 spelled out why:
// "Nethely Kft." and "Nethely Bt." are two companies, and offering them as a
// duplicate pair invites someone to fuse them with one click.

import { LEGAL_SUFFIX } from "@/lib/iktatas/ugy-suggest";
import { normalizePartnerName, taxNumberCore } from "@/lib/partner/identity";

export type PartnerIdentity = {
  id: string;
  name: string;
  taxNumber: string | null;
};

export type DuplicateStrength = "biztos" | "valoszinu" | "lehetseges";

export type DuplicatePair = {
  aId: string;
  bId: string;
  strength: DuplicateStrength;
  reason: string;
};

export const STRENGTH_LABEL: Record<DuplicateStrength, string> = {
  biztos: "Biztos",
  valoszinu: "Valószínű",
  lehetseges: "Lehetséges",
};

const STRENGTH_ORDER: Record<DuplicateStrength, number> = {
  biztos: 0,
  valoszinu: 1,
  lehetseges: 2,
};

// Short names produce nonsense prefix matches ("bau" inside half the trade),
// so the loosest rule only applies from six characters up.
const MIN_PREFIX = 6;

export function legalForm(name: string | null | undefined): string | null {
  const normalized = normalizePartnerName(name);
  const match = LEGAL_SUFFIX.exec(normalized);
  if (!match) return null;
  // A name that is nothing but its legal form ("Kft.") is a name, not a form.
  return normalized.length > match[1].length ? match[1] : null;
}

export function baseName(name: string | null | undefined): string {
  const normalized = normalizePartnerName(name);
  const form = legalForm(name);
  return form ? normalized.slice(0, normalized.length - form.length) : normalized;
}

// Two forms agree if they are the same, or if one side simply does not say.
function formsAgree(a: string | null, b: string | null): boolean {
  return a === null || b === null || a === b;
}

export function findDuplicateCandidates(partners: PartnerIdentity[]): DuplicatePair[] {
  const pairs: DuplicatePair[] = [];

  // O(n²), which is fine for the page's 1000-row ceiling and would not be for
  // a register ten times that; the fix then is to bucket by base name first.
  for (let i = 0; i < partners.length; i++) {
    for (let j = i + 1; j < partners.length; j++) {
      const pair = comparePartners(partners[i], partners[j]);
      if (pair) pairs.push(pair);
    }
  }

  const nameOf = new Map(partners.map((p) => [p.id, p.name]));
  return pairs.sort(
    (a, b) =>
      STRENGTH_ORDER[a.strength] - STRENGTH_ORDER[b.strength] ||
      (nameOf.get(a.aId) ?? "").localeCompare(nameOf.get(b.aId) ?? "", "hu")
  );
}

function comparePartners(a: PartnerIdentity, b: PartnerIdentity): DuplicatePair | null {
  const coreA = taxNumberCore(a.taxNumber);
  const coreB = taxNumberCore(b.taxNumber);

  if (coreA !== null && coreB !== null) {
    // Different taxpayers. Not a candidate at any strength — merge_partner()
    // would refuse it, and showing it would be an invitation to try.
    if (coreA !== coreB) return null;
    return {
      aId: a.id,
      bId: b.id,
      strength: "biztos",
      reason: "Ugyanaz a törzsszám, csak az adószám többi jegye tér el",
    };
  }

  const formA = legalForm(a.name);
  const formB = legalForm(b.name);
  if (!formsAgree(formA, formB)) return null;

  const baseA = baseName(a.name);
  const baseB = baseName(b.name);
  if (baseA === "" || baseB === "") return null;

  if (baseA === baseB) {
    return {
      aId: a.id,
      bId: b.id,
      strength: "valoszinu",
      reason: "Ugyanaz a név, csak az írásmód tér el",
    };
  }

  const [shorter, longer] = baseA.length <= baseB.length ? [baseA, baseB] : [baseB, baseA];
  if (shorter.length >= MIN_PREFIX && longer.startsWith(shorter)) {
    return {
      aId: a.id,
      bId: b.id,
      strength: "lehetseges",
      reason: "Az egyik név a másikkal kezdődik",
    };
  }

  return null;
}

// Mirrors what merge_partner() refuses, so the screen can say why instead of
// offering a button that throws. The database stays the authority — this only
// decides what to render.
export function mergeBlockedReason(
  survivor: PartnerIdentity,
  loser: PartnerIdentity
): string | null {
  if (survivor.id === loser.id) {
    return "Egy partnert nem lehet önmagába olvasztani.";
  }
  const coreA = taxNumberCore(survivor.taxNumber);
  const coreB = taxNumberCore(loser.taxNumber);
  if (coreA !== null && coreB !== null && coreA !== coreB) {
    return `Eltérő adószám (${survivor.taxNumber} és ${loser.taxNumber}) — ez két külön cég.`;
  }
  return null;
}
