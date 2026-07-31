// Which open ügy does this irat belong to? Deterministic, not a model call.
//
// A díjbekérő and the invoice issued against it arrive from the same partner
// for the same amount. That is a rule, and a rule can state its own reason —
// which matters here, because accepting a suggestion stamps a permanent
// iktatószám. A suggestion the user cannot check is worse than none.
//
// Partner comparison goes through the normalized *name*, not partner_id: the
// document being reviewed often has no partner_id yet — extraction only links
// one when the tax number already matches a known partner — so the name typed
// into the form is the only thing available at this point.
//
// The normalization here is deliberately looser than app.normalize_company_name()
// in the database, which resolves partners: this one also strips the legal form,
// because a declined suggestion costs nothing, whereas merging "Nethely Kft."
// into "Nethely Bt." would fuse two legal entities for good.

export type UgyCandidateDocument = {
  docKind: string | null;
  grossAmount: number | null;
  currency: string | null;
};

export type UgyCandidate = {
  id: string;
  prefix: string;
  foszam: number;
  ev: number;
  targy: string | null;
  // The ügy's own partner plus every partner named on its documents.
  partnerNames: (string | null)[];
  documents: UgyCandidateDocument[];
};

export type DocumentFacts = {
  partnerName: string | null;
  grossAmount: number | null;
  currency: string | null;
  docKind: string | null;
};

export type UgySuggestion = {
  ugyId: string;
  label: string;
  reason: string;
  score: number;
};

const PARTNER_POINTS = 2;
const AMOUNT_POINTS = 2;
const DIJBEKERO_POINTS = 1;

// Partner *and* amount both have to agree. Partner alone would suggest last
// month's ügy for every recurring supplier invoice, and a bad suggestion here
// costs more than a missing one: the manual picker still reaches every ügy.
export const MIN_SCORE = PARTNER_POINTS + AMOUNT_POINTS;

const MAX_SUGGESTIONS = 3;

// Money is NUMERIC(18,4); this is a rounding tolerance, not a fuzzy match.
const AMOUNT_TOLERANCE = 0.01;

const LEGAL_SUFFIX = /(spolsro|sro|kft|bt|zrt|nyrt|kkt|gmbh|ltd|llc|inc|bv|oy|ab|spa|sa|ag)$/;

// "Websupport s. r. o." and "Websupport S.R.O." are the same supplier.
// Over-stripping is harmless because both sides go through the same funnel;
// what matters is that two genuinely different names stay different.
export function normalizeCompanyName(raw: string | null | undefined): string {
  if (!raw) return "";
  const bare = raw
    .toLowerCase()
    .normalize("NFD")
    .replace(/[^a-z0-9]+/g, "");
  const stripped = bare.replace(LEGAL_SUFFIX, "");
  // Never strip a name down to nothing: "Kft" alone stays "kft".
  return stripped.length >= 3 ? stripped : bare;
}

function amountsAgree(a: number | null, b: number | null): boolean {
  if (a === null || b === null) return false;
  return Math.abs(a - b) <= AMOUNT_TOLERANCE;
}

function isSzamlaLike(docKind: string | null): boolean {
  return (
    docKind === "szamla" ||
    docKind === "elolegszamla" ||
    docKind === "helyesbito_szamla" ||
    docKind === "sztorno_szamla"
  );
}

export function suggestUgy(doc: DocumentFacts, candidates: UgyCandidate[]): UgySuggestion[] {
  const docPartner = normalizeCompanyName(doc.partnerName);
  const currency = doc.currency?.trim().toUpperCase() || null;

  const scored: UgySuggestion[] = [];

  for (const candidate of candidates) {
    const partnerMatch =
      docPartner !== "" &&
      candidate.partnerNames.some((name) => normalizeCompanyName(name) === docPartner);

    const amountMatch =
      doc.grossAmount !== null &&
      currency !== null &&
      candidate.documents.some(
        (d) =>
          amountsAgree(d.grossAmount, doc.grossAmount) &&
          (d.currency?.trim().toUpperCase() ?? null) === currency
      );

    let score = (partnerMatch ? PARTNER_POINTS : 0) + (amountMatch ? AMOUNT_POINTS : 0);
    if (score < MIN_SCORE) continue;

    // The case this whole feature exists for.
    const answersDijbekero =
      isSzamlaLike(doc.docKind) && candidate.documents.some((d) => d.docKind === "dijbekero");
    if (answersDijbekero) score += DIJBEKERO_POINTS;

    scored.push({
      ugyId: candidate.id,
      label: ugyLabel(candidate),
      reason: answersDijbekero
        ? "Ugyanaz a partner és összeg, és az ügyben már van díjbekérő"
        : "Ugyanaz a partner és ugyanaz az összeg",
      score,
    });
  }

  // Strongest first; between equals the most recently opened ügy wins.
  const rank = new Map(candidates.map((c, i) => [c.id, i]));
  scored.sort(
    (a, b) => b.score - a.score || (rank.get(a.ugyId) ?? 0) - (rank.get(b.ugyId) ?? 0)
  );

  return scored.slice(0, MAX_SUGGESTIONS);
}

export function ugyLabel(candidate: Pick<UgyCandidate, "prefix" | "foszam" | "ev" | "targy">): string {
  const szam = `${candidate.prefix}/${candidate.foszam}/${candidate.ev}`;
  return candidate.targy ? `${szam} — ${candidate.targy}` : szam;
}
