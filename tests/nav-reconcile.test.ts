import { describe, expect, it } from "vitest";
import {
  budapestToday,
  egyeztet,
  navGross,
  type NavSide,
  type RegisterSide,
} from "@/lib/nav/reconcile";
import { invoiceNumberKey } from "@/lib/nav/query";

const MA = "2026-08-03";

function nav(partial: Partial<NavSide> & { id: string; invoiceNumber: string }): NavSide {
  return {
    invoiceNumberKey: invoiceNumberKey(partial.invoiceNumber),
    partnerTaxCore: "12345676",
    partnerGroupTaxCore: null,
    partnerName: "Nethely Kft.",
    issueDate: "2026-07-03",
    currency: "HUF",
    netAmount: 100_000,
    vatAmount: 27_000,
    invoiceOperation: "CREATE",
    invoiceCategory: "NORMAL",
    insDate: "2026-07-03T09:12:33Z",
    ...partial,
  };
}

function irat(
  partial: Partial<RegisterSide> & { id: string; iratSzama: string }
): RegisterSide {
  return {
    ugyId: "u1",
    iktatoszam: "IKT/1-1/2026",
    iratSzamaKey: invoiceNumberKey(partial.iratSzama),
    partnerTaxCore: "12345676",
    partnerName: "Nethely Kft.",
    docKind: "szamla",
    issueDate: "2026-07-03",
    currency: "HUF",
    grossAmount: 127_000,
    ervenytelenitve: false,
    ...partial,
  };
}

describe("a párosítás", () => {
  it("a számlaszám egyezése párosít", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "2026/00123" })],
      register: [irat({ id: "d1", iratSzama: "2026/00123" })],
      today: MA,
    });
    expect(result.egyezik).toHaveLength(1);
    expect(result.hianyzik).toEqual([]);
    expect(result.nincsNavnal).toEqual([]);
  });

  it("a szóköz és a kisbetű nem számít különbségnek", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "AB 2026/123" })],
      register: [irat({ id: "d1", iratSzama: "ab2026/123" })],
      today: MA,
    });
    expect(result.egyezik).toHaveLength(1);
  });

  // Ez a funkció létének az oka: a saját postaládánkból nem látszik, ami meg
  // sem érkezett. A NAV listája az egyetlen, ami nem tőlünk függ.
  it("kimondja, ha a NAV tud egy számláról, ami nálunk nincs meg", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "2026/00999" })],
      register: [],
      today: MA,
    });
    expect(result.hianyzik.map((r) => r.invoiceNumber)).toEqual(["2026/00999"]);
  });

  it("kimondja, ha nálunk megvan, de a NAV nem tud róla", () => {
    const result = egyeztet({
      nav: [],
      register: [irat({ id: "d1", iratSzama: "2026/00123" })],
      today: MA,
    });
    expect(result.nincsNavnal.map((r) => r.id)).toEqual(["d1"]);
  });

  // A kiállítónak a következő munkanap végéig van ideje bejelenteni. Egy
  // tegnapi számlát hiányolni napi hamis riasztás, és a hamis riasztás
  // megtanítja az embert átlapozni a listát.
  it("a tegnap kelt számlát nem hiányolja a NAV-nál", () => {
    const result = egyeztet({
      nav: [],
      register: [irat({ id: "d1", iratSzama: "2026/00123", issueDate: "2026-08-02" })],
      today: MA,
    });
    expect(result.nincsNavnal).toEqual([]);
    expect(result.friss.map((r) => r.id)).toEqual(["d1"]);
  });
});

describe("amit nem hasonlít össze", () => {
  it("a díjbekérőt és a nyugtát nem várja a NAV-nál", () => {
    const result = egyeztet({
      nav: [],
      register: [
        irat({ id: "d1", iratSzama: "DB-1", docKind: "dijbekero" }),
        irat({ id: "d2", iratSzama: "NY-1", docKind: "nyugta" }),
      ],
      today: MA,
    });
    expect(result.nincsNavnal).toEqual([]);
    expect(result.kihagyva.map((k) => k.ok)).toEqual(["nem_szamla", "nem_szamla"]);
  });

  it("a külföldi szállító számláját nem hiányolja", () => {
    const result = egyeztet({
      nav: [],
      register: [irat({ id: "d1", iratSzama: "INV-9", partnerTaxCore: "DE811907980" })],
      today: MA,
    });
    expect(result.kihagyva[0]?.ok).toBe("kulfoldi");
  });

  it("az érvénytelenített iratot kihagyja", () => {
    const result = egyeztet({
      nav: [],
      register: [irat({ id: "d1", iratSzama: "2026/00123", ervenytelenitve: true })],
      today: MA,
    });
    expect(result.kihagyva[0]?.ok).toBe("ervenytelenitve");
  });

  it("a számlaszám nélküli iratot nem próbálja párosítani", () => {
    const result = egyeztet({
      nav: [],
      register: [irat({ id: "d1", iratSzama: "" })],
      today: MA,
    });
    expect(result.kihagyva[0]?.ok).toBe("nincs_szam");
  });
});

describe("az elgépelt számlaszám", () => {
  it("ugyanaz a partner, kelt és összeg mellett valószínű egyezés", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "2026/00123" })],
      register: [irat({ id: "d1", iratSzama: "2026/0123" })],
      today: MA,
    });
    expect(result.valoszinu).toHaveLength(1);
    expect(result.hianyzik).toEqual([]);
    expect(result.nincsNavnal).toEqual([]);
    expect(result.valoszinu[0].nav.invoiceNumber).toBe("2026/00123");
    expect(result.valoszinu[0].irat.iratSzama).toBe("2026/0123");
  });

  it("adószám nélkül nem talál ki egyezést az összegből", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "2026/00123", partnerTaxCore: null })],
      register: [irat({ id: "d1", iratSzama: "2026/0123", partnerTaxCore: null })],
      today: MA,
    });
    expect(result.valoszinu).toEqual([]);
    expect(result.hianyzik).toHaveLength(1);
  });

  it("eltérő összegnél nem párosít", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "2026/00123" })],
      register: [irat({ id: "d1", iratSzama: "2026/0123", grossAmount: 200_000 })],
      today: MA,
    });
    expect(result.valoszinu).toEqual([]);
    expect(result.hianyzik).toHaveLength(1);
    expect(result.nincsNavnal).toHaveLength(1);
  });
});

describe("a magyar sajátosságok", () => {
  // A csoportos adóalany a csoport adószámán jelent, a számlán viszont a tag
  // adószáma szerepel. A kettő közül bármelyik egyezése ugyanazt jelenti.
  it("az áfacsoport tagjának adószámán is párosít", () => {
    const result = egyeztet({
      nav: [
        nav({
          id: "n1",
          invoiceNumber: "2026/00123",
          partnerTaxCore: "99999995",
          partnerGroupTaxCore: "12345676",
        }),
      ],
      register: [irat({ id: "d1", iratSzama: "2026/00123", partnerTaxCore: "12345676" })],
      today: MA,
    });
    expect(result.egyezik).toHaveLength(1);
  });

  it("a hiányzó adószám nem akadálya a számlaszám szerinti egyezésnek", () => {
    const result = egyeztet({
      nav: [nav({ id: "n1", invoiceNumber: "2026/00123" })],
      register: [
        irat({ id: "d1", iratSzama: "2026/00123", partnerTaxCore: "12345676", docKind: "szamla" }),
      ],
      today: MA,
    });
    expect(result.egyezik).toHaveLength(1);
  });

  it("két különböző szállító azonos számlaszáma nem keveredik össze", () => {
    const result = egyeztet({
      nav: [
        nav({ id: "n1", invoiceNumber: "1/2026", partnerTaxCore: "12345676" }),
        nav({ id: "n2", invoiceNumber: "1/2026", partnerTaxCore: "23456787", partnerName: "Más Kft." }),
      ],
      register: [irat({ id: "d1", iratSzama: "1/2026", partnerTaxCore: "23456787" })],
      today: MA,
    });
    expect(result.egyezik).toHaveLength(1);
    expect(result.egyezik[0].nav.partnerTaxCore).toBe("23456787");
    expect(result.hianyzik.map((r) => r.partnerTaxCore)).toEqual(["12345676"]);
  });

  // Egy számla bejelentése javítható: ugyanaz a számla, két bejelentés. Egy
  // számla, nem kettő — különben a lista duplán hiányolná.
  it("ugyanannak a számlának a két bejelentése egy tétel", () => {
    const result = egyeztet({
      nav: [
        nav({ id: "n1", invoiceNumber: "2026/00123", insDate: "2026-07-03T09:00:00Z" }),
        nav({ id: "n2", invoiceNumber: "2026/00123", insDate: "2026-07-04T09:00:00Z" }),
      ],
      register: [],
      today: MA,
    });
    expect(result.hianyzik).toHaveLength(1);
    expect(result.hianyzik[0].insDate).toBe("2026-07-04T09:00:00Z");
  });

  // Az egyszerűsített számlán nincs olyan nettó/áfa bontás, amiből a bruttó
  // összeadható lenne. Inkább ne mondjunk összeget, mint rosszat: az összeg
  // csak javaslatot tesz, elutasítani sosem tud.
  it("egyszerűsített számlára nem számol bruttót", () => {
    expect(navGross(nav({ id: "n1", invoiceNumber: "x", invoiceCategory: "SIMPLIFIED" }))).toBeNull();
    expect(navGross(nav({ id: "n1", invoiceNumber: "x" }))).toBe(127_000);
  });
});

describe("a mai nap", () => {
  it("budapesti idő szerint dönt, nem UTC szerint", () => {
    expect(budapestToday(new Date("2026-08-03T23:30:00.000Z"))).toBe("2026-08-04");
    expect(budapestToday(new Date("2026-08-03T21:00:00.000Z"))).toBe("2026-08-03");
  });
});

describe("a párosítás következetes", () => {
  it("ugyanarra a két listára mindig ugyanazt adja", () => {
    const navRows = [
      nav({ id: "n1", invoiceNumber: "A/1" }),
      nav({ id: "n2", invoiceNumber: "A/2" }),
      nav({ id: "n3", invoiceNumber: "A/3" }),
    ];
    const iratok = [
      irat({ id: "d1", iratSzama: "A/2" }),
      irat({ id: "d2", iratSzama: "A/3" }),
    ];
    const first = egyeztet({ nav: navRows, register: iratok, today: MA });
    const second = egyeztet({
      nav: [...navRows].reverse(),
      register: [...iratok].reverse(),
      today: MA,
    });
    expect(second.egyezik.map((p) => p.irat.id).sort()).toEqual(
      first.egyezik.map((p) => p.irat.id).sort()
    );
    expect(second.hianyzik.map((r) => r.invoiceNumber)).toEqual(
      first.hianyzik.map((r) => r.invoiceNumber)
    );
  });
});
