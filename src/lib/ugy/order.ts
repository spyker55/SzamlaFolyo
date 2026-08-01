import { daysBetween } from "@/lib/fizetes/schedule";
import { isRunning } from "@/lib/ugy/status";

// What the list is for: seeing what needs attention. So an ugy with a
// deadline outranks one without, and the nearest deadline comes first. Ugyek
// with no deadline fall to the bottom in reverse foszam order — newest first,
// like the iktatokonyv.
//
// A finished ugy sinks below every running one, whatever its deadline says.
// Sorting purely by date put an ugy archived in May at the very top of the
// list, above everything still open, because its deadline was the oldest.
export type UgyOrderKey = {
  hatarido: string | null;
  foszam: number;
  ev: number;
  status: string;
};

export function compareUgyForList(a: UgyOrderKey, b: UgyOrderKey): number {
  const aRunning = isRunning(a.status);
  if (aRunning !== isRunning(b.status)) return aRunning ? -1 : 1;

  if (a.hatarido && b.hatarido) {
    if (a.hatarido !== b.hatarido) return a.hatarido < b.hatarido ? -1 : 1;
    return byNumber(a, b);
  }
  if (a.hatarido) return -1;
  if (b.hatarido) return 1;
  return byNumber(a, b);
}

function byNumber(a: UgyOrderKey, b: UgyOrderKey): number {
  return b.ev - a.ev || b.foszam - a.foszam;
}

export type DeadlineState = "lejart" | "ma" | "kozeli" | "tavoli" | "nincs" | "lezarult";

// "kozeli" is a week, the same window the fizetesi naptar treats as urgent,
// so the two screens do not disagree about what counts as soon.
//
// "lezarult" comes first and outranks everything: once the ugy is closed or
// archived its deadline stopped being a deadline. The date is still shown —
// it is part of the record — but it is not news, and it is not red.
export function deadlineState(
  hatarido: string | null,
  today: string,
  status: string
): DeadlineState {
  if (!isRunning(status)) return "lezarult";
  if (!hatarido) return "nincs";
  const days = daysBetween(today, hatarido);
  if (days < 0) return "lejart";
  if (days === 0) return "ma";
  if (days <= 7) return "kozeli";
  return "tavoli";
}

// The commentary under the date, and empty when there is nothing to add: the
// date itself already says everything about a finished ugy, and an ugy with no
// deadline is shown as "—" once, not twice.
export function deadlineText(
  hatarido: string | null,
  today: string,
  status: string
): string {
  if (!hatarido || !isRunning(status)) return "";
  const days = daysBetween(today, hatarido);
  if (days < 0) return `${-days} napja lejárt`;
  if (days === 0) return "ma esedékes";
  return `${days} nap múlva`;
}
