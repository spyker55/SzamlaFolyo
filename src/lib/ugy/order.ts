import { daysBetween } from "@/lib/fizetes/schedule";

// What the list is for: seeing what needs attention. So an ugy with a
// deadline outranks one without, and the nearest deadline comes first. Ugyek
// with no deadline fall to the bottom in reverse foszam order — newest first,
// like the iktatokonyv.
export type UgyOrderKey = {
  hatarido: string | null;
  foszam: number;
  ev: number;
};

export function compareUgyForList(a: UgyOrderKey, b: UgyOrderKey): number {
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

export type DeadlineState = "lejart" | "ma" | "kozeli" | "tavoli" | "nincs";

// "kozeli" is a week, the same window the fizetesi naptar treats as urgent,
// so the two screens do not disagree about what counts as soon.
export function deadlineState(hatarido: string | null, today: string): DeadlineState {
  if (!hatarido) return "nincs";
  const days = daysBetween(today, hatarido);
  if (days < 0) return "lejart";
  if (days === 0) return "ma";
  if (days <= 7) return "kozeli";
  return "tavoli";
}

export function deadlineText(hatarido: string | null, today: string): string {
  if (!hatarido) return "—";
  const days = daysBetween(today, hatarido);
  if (days < 0) return `${-days} napja lejárt`;
  if (days === 0) return "ma esedékes";
  return `${days} nap múlva`;
}
