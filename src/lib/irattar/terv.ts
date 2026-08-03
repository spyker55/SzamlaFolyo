// Az irattári terv számolása: meddig kell megőrizni, és mikortól nem kell.
//
// Pure on purpose. The database holds the terv (public.irattari_tetel) and the
// ügy's classification; everything that turns those two into a date lives
// here, so the ügy screen, the terv screen and the test all answer the same
// question the same way.
//
// The one rule the whole file rests on: a null retention period is not a
// missing value, it is **nem selejtezhető**. A number is a permission to
// destroy once it runs out; the absence of one is a refusal that never does.

export type IrattariTetel = {
  id: string;
  tetelszam: string;
  nev: string;
  orzesiIdoEv: number | null;
  jogszabaly: string | null;
  megjegyzes: string | null;
  sorrend: number;
  aktiv: boolean;
};

export const NEM_SELEJTEZHETO_JEL = "NS";

// "P-2/8", "M-1/NS" — the tételszám and what it means, in the form that is
// short enough to print next to an iktatószám.
export function irattariJel(tetelszam: string, orzesiIdoEv: number | null): string {
  const suffix = orzesiIdoEv === null ? NEM_SELEJTEZHETO_JEL : String(orzesiIdoEv);
  return `${tetelszam}/${suffix}`;
}

export type MegorzesAllapot =
  // The ügy is still running, so the clock has not started.
  | "nyitva"
  | "megorzendo"
  | "selejtezheto"
  | "nem_selejtezheto"
  // Classified into nothing: the ügy has no tétel, so the system has no
  // opinion. Never guessed — an unclassified ügy is kept.
  | "besorolatlan";

export type Megorzes = {
  allapot: MegorzesAllapot;
  // The last year of required retention, and the first year disposal is
  // permitted. Kept as years and not dates because that is how both the
  // Számviteli törvény and every irattári terv are written.
  utolsoEv: number | null;
  selejtezhetoEv: number | null;
  hatralevoEv: number | null;
};

// lezarasEv: the year the ügy was closed, in Budapest time. Null while it is
// still open.
//
// The Számviteli törvény counts from the business year of the bizonylat; an
// irattári terv counts from the closing of the ügy. For an ügy opened and
// closed inside one year they are the same, and where they differ the closing
// is the later date — so counting from it never disposes of anything early.
export function megorzes(
  lezarasEv: number | null,
  orzesiIdoEv: number | null | undefined,
  mostEv: number,
  besorolt = true
): Megorzes {
  const semmi = { utolsoEv: null, selejtezhetoEv: null, hatralevoEv: null };

  if (!besorolt) return { allapot: "besorolatlan", ...semmi };
  if (orzesiIdoEv === null || orzesiIdoEv === undefined) {
    return { allapot: "nem_selejtezheto", ...semmi };
  }
  if (lezarasEv === null) return { allapot: "nyitva", ...semmi };

  // "8 évig kell megőrizni" a 2026-ban lezárt ügyre azt jelenti, hogy 2027-től
  // 2034-ig — nyolc teljes év —, tehát 2034. december 31-ig. Selejtezni a
  // következő év elejétől lehet.
  const utolsoEv = lezarasEv + orzesiIdoEv;
  const selejtezhetoEv = utolsoEv + 1;

  if (mostEv >= selejtezhetoEv) {
    return { allapot: "selejtezheto", utolsoEv, selejtezhetoEv, hatralevoEv: 0 };
  }
  return {
    allapot: "megorzendo",
    utolsoEv,
    selejtezhetoEv,
    hatralevoEv: selejtezhetoEv - mostEv,
  };
}

export function megorzesSzoveg(m: Megorzes): string {
  switch (m.allapot) {
    case "nem_selejtezheto":
      return "Nem selejtezhető.";
    case "besorolatlan":
      return "Nincs irattári tétele, ezért a megőrzési idő nem számolható. Amíg nincs besorolva, megmarad.";
    case "nyitva":
      return "Az ügy még nyitva; a megőrzési idő a lezárással indul.";
    case "megorzendo":
      return `${m.utolsoEv}. december 31-ig őrzendő, utána selejtezhető.`;
    case "selejtezheto":
      return `${m.selejtezhetoEv}. január 1. óta selejtezhető.`;
  }
}

// Badge colour on the screens. Only "selejtezhető" is loud: it is the one
// state that asks somebody to do something.
export function megorzesStilus(allapot: MegorzesAllapot): string {
  if (allapot === "selejtezheto") return "badge-amber";
  if (allapot === "nem_selejtezheto") return "badge-slate";
  if (allapot === "besorolatlan") return "badge-red";
  return "badge-blue";
}

export const MEGORZES_LABEL: Record<MegorzesAllapot, string> = {
  nyitva: "Nyitott ügy",
  megorzendo: "Megőrzendő",
  selejtezheto: "Selejtezhető",
  nem_selejtezheto: "Nem selejtezhető",
  besorolatlan: "Besorolatlan",
};

// The year in Budapest, not in UTC: an ügy closed at 00:30 on 1 January
// belongs to the new year, and taking the UTC year would keep it a year
// longer than it needs to be — or, worse, a year less.
export function budapestEv(iso: string | null | undefined): number | null {
  if (!iso) return null;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;
  return Number(
    new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Budapest", year: "numeric" }).format(date)
  );
}

// Validation for the terv editor, in the layer both the form and the action
// can call. The database repeats the range check; this is what turns it into
// a Hungarian sentence before the round trip.
export type TetelInput = {
  tetelszam: string;
  nev: string;
  orzesiIdoEv: number | null;
  jogszabaly: string;
  megjegyzes: string;
};

export function validateTetel(input: TetelInput): string | null {
  if (input.tetelszam.trim() === "") return "A tételszám nem lehet üres.";
  if (input.tetelszam.trim().length > 16) return "A tételszám legfeljebb 16 karakter lehet.";
  if (input.nev.trim() === "") return "A tétel megnevezése nem lehet üres.";
  if (input.orzesiIdoEv !== null) {
    if (!Number.isInteger(input.orzesiIdoEv)) return "Az őrzési idő csak egész év lehet.";
    if (input.orzesiIdoEv < 1 || input.orzesiIdoEv > 100) {
      return "Az őrzési idő 1 és 100 év között lehet. Ha egyáltalán nem selejtezhető, hagyd üresen.";
    }
  }
  return null;
}
