// The iktatoszam is text ("IKT/10-1/2026"), so ordering the iktatokonyv by
// that column sorts it lexicographically: 9 lands above 10, and 100 above 11.
// The book is ordered by the numeric parts it is built from instead.

export type IktatoszamParts = {
  ev: number | null;
  foszam: number | null;
  alszam: number | null;
};

// Newest first: year, then foszam, then alszam. A row with no ugy yet (which
// an iktatott document always has) sorts last rather than jumping to the top.
export function compareIktatoszamDesc(a: IktatoszamParts, b: IktatoszamParts): number {
  return (
    num(b.ev) - num(a.ev) || num(b.foszam) - num(a.foszam) || num(b.alszam) - num(a.alszam)
  );
}

function num(v: number | null): number {
  return v ?? Number.NEGATIVE_INFINITY;
}
