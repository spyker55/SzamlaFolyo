// The ugy status machine. The same set of transitions is enforced in
// app.protect_ugy(); tests/ugy-status.test.ts rebuilds this list from the
// migration so the screen can never offer a button the database refuses.

export const UGY_STATUSES = [
  "folyamatban",
  "felfuggesztve",
  "lezart",
  "irattarazott",
] as const;

export type UgyStatus = (typeof UGY_STATUSES)[number];

export const UGY_STATUS_LABEL: Record<UgyStatus, string> = {
  folyamatban: "Folyamatban",
  felfuggesztve: "Felfüggesztve",
  lezart: "Lezárt",
  irattarazott: "Irattárazott",
};

// 'folyamatban' never jumps straight to the archive: an ugy is closed first,
// which is the actual clerical workflow.
export const UGY_TRANSITIONS = [
  "folyamatban->felfuggesztve",
  "folyamatban->lezart",
  "felfuggesztve->folyamatban",
  "felfuggesztve->lezart",
  "lezart->folyamatban",
  "lezart->felfuggesztve",
  "lezart->irattarazott",
  "irattarazott->lezart",
] as const;

// Derived from the list above rather than from every pair of statuses, so
// TRANSITION_LABEL below is exhaustively checked against exactly the moves
// the database allows — a new transition will not compile until it is named.
export type Transition = (typeof UGY_TRANSITIONS)[number];

// The button text depends on where you are coming from, not only on where you
// are going: 'lezart' is "Lezárás" from an open ugy but "Kivétel az
// irattárból" from the archive.
export const TRANSITION_LABEL: Record<Transition, string> = {
  "folyamatban->felfuggesztve": "Felfüggesztés",
  "folyamatban->lezart": "Lezárás",
  "felfuggesztve->folyamatban": "Folytatás",
  "felfuggesztve->lezart": "Lezárás",
  "lezart->folyamatban": "Újranyitás",
  "lezart->felfuggesztve": "Felfüggesztés",
  "lezart->irattarazott": "Irattárba helyezés",
  "irattarazott->lezart": "Kivétel az irattárból",
};

export function isUgyStatus(value: string | null | undefined): value is UgyStatus {
  return UGY_STATUSES.includes(value as UgyStatus);
}

export function canTransition(from: UgyStatus, to: UgyStatus): boolean {
  return UGY_TRANSITIONS.includes(`${from}->${to}` as Transition);
}

export function nextStatuses(from: UgyStatus): UgyStatus[] {
  return UGY_STATUSES.filter((to) => canTransition(from, to));
}

export function transitionLabel(from: UgyStatus, to: UgyStatus): string {
  return TRANSITION_LABEL[`${from}->${to}` as Transition] ?? UGY_STATUS_LABEL[to];
}

// Mirrors the check inside iktat_document(): a closed or archived ugy takes
// no further iratok. A suspended one still does — suspension is about the
// case being on hold, not about the post stopping.
export function acceptsNewIrat(status: UgyStatus): boolean {
  return status === "folyamatban" || status === "felfuggesztve";
}

// An archived ugy is put away: its metadata is frozen until it is taken back
// out. app.protect_ugy() enforces this; the screen just stops offering it.
export function isEditable(status: UgyStatus): boolean {
  return status !== "irattarazott";
}

// Whether the ugy is still being worked on.
//
// It happens to be the same two statuses that accept a new irat, but it is a
// different question and is written separately on purpose: this one decides
// whether a deadline still means anything. IKT/6/2026 went into the archive in
// May, and the list went on shouting "84 napja lejárt" at it every day since,
// sorted above the work that was actually open. A finished ugy's deadline is a
// historical fact, not a task.
//
// Takes a plain string because the lists carry whatever the database returned.
export function isRunning(status: string): boolean {
  return status === "folyamatban" || status === "felfuggesztve";
}
