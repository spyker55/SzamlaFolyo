import { describe, expect, it } from "vitest";
import {
  bucketFor,
  buildSchedule,
  daysBetween,
  sumByCurrency,
  withoutSupersededDijbekero,
  type PayableDocument,
} from "@/lib/fizetes/schedule";

const TODAY = "2026-07-31";

function doc(over: Partial<PayableDocument> = {}): PayableDocument {
  return {
    id: crypto.randomUUID(),
    iktatoszam: "IKT/1-1/2026",
    ugyId: null,
    docKind: "szamla",
    partnerName: "Teszt Kft.",
    targy: "Teszt",
    dueDate: "2026-08-15",
    grossAmount: 1000,
    currency: "HUF",
    fizetveAt: null,
    ...over,
  };
}

describe("határidő-számítás", () => {
  it("nem csúszik el időzóna miatt", () => {
    expect(daysBetween("2026-07-31", "2026-08-01")).toBe(1);
    expect(daysBetween("2026-07-31", "2026-07-30")).toBe(-1);
    // Across a DST change, which a local-time parse would get wrong.
    expect(daysBetween("2026-10-24", "2026-10-26")).toBe(2);
  });

  it("a megfelelő sávba sorol", () => {
    expect(bucketFor("2026-07-30", TODAY)).toBe("lejart");
    expect(bucketFor("2026-07-31", TODAY)).toBe("ma");
    expect(bucketFor("2026-08-07", TODAY)).toBe("het");
    expect(bucketFor("2026-08-08", TODAY)).toBe("honap");
    expect(bucketFor("2026-08-30", TODAY)).toBe("honap");
    expect(bucketFor("2026-08-31", TODAY)).toBe("kesobb");
    expect(bucketFor(null, TODAY)).toBe("nincs_hatarido");
  });
});

describe("mi számít fizetendőnek", () => {
  it("a nyugtát kihagyja, a díjbekérőt beveszi", () => {
    const schedule = buildSchedule(
      [
        doc({ docKind: "nyugta", iktatoszam: "IKT/1-1/2026" }),
        doc({ docKind: "dijbekero", iktatoszam: "IKT/2-1/2026" }),
        doc({ docKind: "szallitolevel", iktatoszam: "IKT/3-1/2026" }),
        doc({ docKind: "szerzodes", iktatoszam: "IKT/4-1/2026" }),
      ],
      TODAY
    );

    // A nyugta already changed hands at the till; a szállítólevél and a
    // szerződés are not requests for money.
    expect(schedule.count).toBe(1);
    expect(schedule.groups[0].entries[0].iktatoszam).toBe("IKT/2-1/2026");
  });

  it("a kifizetettet és az összeg nélkülit kihagyja", () => {
    const schedule = buildSchedule(
      [
        doc({ fizetveAt: "2026-07-20" }),
        doc({ grossAmount: null }),
        doc({ currency: null }),
        doc({ iktatoszam: "IKT/9-1/2026" }),
      ],
      TODAY
    );
    expect(schedule.count).toBe(1);
    expect(schedule.groups[0].entries[0].iktatoszam).toBe("IKT/9-1/2026");
  });
});

describe("díjbekérő és a rá kiállított számla", () => {
  // The real Websupport pair: same ügy, same amount, two documents, one debt.
  const dijbekero = doc({
    id: "d1",
    docKind: "dijbekero",
    ugyId: "ugy-4",
    grossAmount: 13598,
    iktatoszam: "IKT/4-1/2026",
  });
  const szamla = doc({
    id: "s1",
    docKind: "szamla",
    ugyId: "ugy-4",
    grossAmount: 13598,
    iktatoszam: "IKT/4-2/2026",
  });

  it("nem számolja duplán", () => {
    const schedule = buildSchedule([dijbekero, szamla], TODAY);
    expect(schedule.count).toBe(1);
    expect(schedule.groups[0].entries[0].id).toBe("s1");
    expect(schedule.totals).toEqual([{ currency: "HUF", amount: 13598 }]);
  });

  it("a már kifizetett számla is elnyomja a díjbekérőt", () => {
    // Otherwise settling the invoice would resurrect the request as a debt.
    const schedule = buildSchedule([dijbekero, { ...szamla, fizetveAt: "2026-07-25" }], TODAY);
    expect(schedule.count).toBe(0);
    expect(schedule.totals).toEqual([]);
  });

  it("eltérő összegnél mindkettőt mutatja", () => {
    // Visible duplication is a mistake the user can catch; a silently hidden
    // debt is not.
    const schedule = buildSchedule([dijbekero, { ...szamla, grossAmount: 15000 }], TODAY);
    expect(schedule.count).toBe(2);
  });

  it("külön ügyben nem nyomja el", () => {
    const schedule = buildSchedule([dijbekero, { ...szamla, ugyId: "ugy-9" }], TODAY);
    expect(schedule.count).toBe(2);
  });

  it("ügy nélküli díjbekérőt érintetlenül hagy", () => {
    const kept = withoutSupersededDijbekero([{ ...dijbekero, ugyId: null }, szamla]);
    expect(kept).toHaveLength(2);
  });
});

describe("összesítés", () => {
  it("nem ad össze különböző pénznemeket", () => {
    const totals = sumByCurrency([
      doc({ grossAmount: 1000, currency: "HUF" }),
      doc({ grossAmount: 2000, currency: "HUF" }),
      doc({ grossAmount: 50, currency: "EUR" }),
    ]);
    expect(totals).toEqual([
      { currency: "EUR", amount: 50 },
      { currency: "HUF", amount: 3000 },
    ]);
  });

  it("a sztornó csökkenti a tartozást", () => {
    const totals = sumByCurrency([
      doc({ grossAmount: 10000 }),
      doc({ docKind: "sztorno_szamla", grossAmount: -10000 }),
    ]);
    expect(totals).toEqual([{ currency: "HUF", amount: 0 }]);
  });

  it("a pénznemet egységesíti", () => {
    expect(sumByCurrency([doc({ currency: "huf" }), doc({ currency: " HUF " })])).toEqual([
      { currency: "HUF", amount: 2000 },
    ]);
  });
});

describe("csoportosítás", () => {
  it("sávonként, határidő szerint rendezve, üres sáv nélkül", () => {
    const schedule = buildSchedule(
      [
        doc({ dueDate: "2026-08-20", iktatoszam: "IKT/3-1/2026" }),
        doc({ dueDate: "2026-07-10", iktatoszam: "IKT/1-1/2026" }),
        doc({ dueDate: "2026-07-20", iktatoszam: "IKT/2-1/2026" }),
      ],
      TODAY
    );

    expect(schedule.groups.map((g) => g.bucket)).toEqual(["lejart", "honap"]);
    // Oldest debt first inside the overdue group.
    expect(schedule.groups[0].entries.map((e) => e.iktatoszam)).toEqual([
      "IKT/1-1/2026",
      "IKT/2-1/2026",
    ]);
    expect(schedule.groups[0].entries[0].daysLeft).toBe(-21);
    expect(schedule.groups[0].totals).toEqual([{ currency: "HUF", amount: 2000 }]);
  });
});
