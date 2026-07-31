import { describe, expect, it } from "vitest";
import {
  accountingTotals,
  COLUMNS,
  CSV_SEPARATOR,
  csvNumber,
  csvText,
  toCsv,
  UTF8_BOM,
  type ExportRow,
} from "@/lib/export/csv";
import {
  exportBaseName,
  isIsoDate,
  monthLabel,
  monthRange,
  recentMonths,
} from "@/lib/export/period";

function row(overrides: Partial<ExportRow> = {}): ExportRow {
  return {
    iktatoszam: "IKT/1-1/2026",
    erkezettAt: "2026-07-01",
    issueDate: "2026-06-28",
    dueDate: "2026-07-15",
    docKind: "szamla",
    direction: "bejovo",
    partnerName: "Websupport s. r. o.",
    partnerTaxNumber: "12345678-1-42",
    iratSzama: "SZ-2026-012694",
    netAmount: 13366,
    vatAmount: 3609,
    grossAmount: 16975,
    currency: "HUF",
    fizetveAt: null,
    targy: "Hosting",
    ugyTargy: "Hosting és domain",
    eloado: "Teszt Elek",
    irattariJel: "P-3",
    ervenytelen: false,
    ervenytelenitesIndoka: null,
    fileName: "szamla.pdf",
    ...overrides,
  };
}

describe("period", () => {
  it("closes a month on its real last day", () => {
    expect(monthRange("2026-07")).toEqual({ from: "2026-07-01", to: "2026-07-31" });
    expect(monthRange("2026-02")).toEqual({ from: "2026-02-01", to: "2026-02-28" });
    // Leap year: an inclusive range that stopped at the 28th would silently
    // drop a day's iratok from the handover.
    expect(monthRange("2024-02")).toEqual({ from: "2024-02-01", to: "2024-02-29" });
    expect(monthRange("2026-04")).toEqual({ from: "2026-04-01", to: "2026-04-30" });
  });

  it("refuses a month that does not exist", () => {
    expect(monthRange("2026-13")).toBeNull();
    expect(monthRange("2026-00")).toBeNull();
    expect(monthRange("2026-7")).toBeNull();
    expect(monthRange("")).toBeNull();
  });

  it("rejects a date the calendar does not have", () => {
    expect(isIsoDate("2026-07-31")).toBe(true);
    // Date would happily normalize this to March 3rd.
    expect(isIsoDate("2026-02-31")).toBe(false);
    expect(isIsoDate("2026-7-1")).toBe(false);
  });

  it("walks back across the year boundary", () => {
    const months = recentMonths(4, new Date("2026-02-10T09:00:00Z"));
    expect(months).toEqual(["2026-02", "2026-01", "2025-12", "2025-11"]);
  });

  it("names the file after the company and the period", () => {
    expect(exportBaseName("Napfény Lakópark Kft.", "2026-07-01", "2026-07-31")).toBe(
      "napfeny-lakopark-kft_2026-07"
    );
    expect(exportBaseName("", "2026-01-01", "2026-03-31")).toBe("iratok_2026-01-01_2026-03-31");
  });

  it("labels a month in Hungarian", () => {
    expect(monthLabel("2026-07")).toBe("2026. július");
  });
});

describe("csv numbers", () => {
  it("uses the Hungarian decimal mark and no grouping", () => {
    // Grouping would make Excel read the cell as text, and every sum in the
    // bookkeeper's sheet would be zero.
    expect(csvNumber(1612900.25)).toBe("1612900,25");
    expect(csvNumber(16975)).toBe("16975,00");
    expect(csvNumber(0)).toBe("0,00");
  });

  it("keeps the storage scale when it carries information", () => {
    expect(csvNumber(0.0001)).toBe("0,0001");
    expect(csvNumber(0.001)).toBe("0,001");
    expect(csvNumber(null)).toBe("");
  });

  it("keeps a sztorno negative", () => {
    expect(csvNumber(-16975)).toBe("-16975,00");
  });
});

describe("csv text", () => {
  it("quotes and escapes", () => {
    expect(csvText('Kft. "Alfa"')).toBe('"Kft. ""Alfa"""');
    expect(csvText("Bútor; szék")).toBe('"Bútor; szék"');
    expect(csvText("")).toBe("");
    expect(csvText(null)).toBe("");
  });

  it("defuses a cell Excel would run as a formula", () => {
    // The partner name comes from a model reading a PDF, so it is untrusted
    // text landing in a spreadsheet.
    expect(csvText("=HYPERLINK(\"http://evil\",\"kattints\")")).toBe(
      '"\'=HYPERLINK(""http://evil"",""kattints"")"'
    );
    expect(csvText("+1234")).toBe("\"'+1234\"");
    expect(csvText("@SUM(A1)")).toBe("\"'@SUM(A1)\"");
    expect(csvText("-Websupport")).toBe("\"'-Websupport\"");
  });
});

describe("toCsv", () => {
  it("starts with the BOM and the header, and uses CRLF", () => {
    const csv = toCsv([]);
    expect(csv.startsWith(UTF8_BOM)).toBe(true);
    const [header] = csv.slice(UTF8_BOM.length).split("\r\n");
    expect(header.split(CSV_SEPARATOR)).toHaveLength(COLUMNS.length);
    expect(header).toContain('"Iktatószám"');
    expect(header).toContain('"Bruttó"');
  });

  it("writes amounts unquoted so Excel reads them as numbers", () => {
    const csv = toCsv([row({ grossAmount: -16975, netAmount: -13366, vatAmount: -3609 })]);
    const line = csv.split("\r\n")[1];
    expect(line).toContain(";-16975,00;");
    // The formula guard must not touch a negative amount.
    expect(line).not.toContain("'-16975");
  });

  it("marks a withdrawn irat instead of hiding it", () => {
    const csv = toCsv([row({ ervenytelen: true, ervenytelenitesIndoka: "téves" })]);
    expect(csv).toContain('"ÉRVÉNYTELENÍTVE"');
    expect(csv).toContain('"téves"');
  });

  it("keeps every row on one record even with a multi-line targy", () => {
    const csv = toCsv([row({ targy: "első sor\nmásodik sor" })]);
    // Header + one record + the trailing terminator.
    expect(csv.trimEnd().split("\r\n")).toHaveLength(2);
  });
});

describe("totals", () => {
  it("never mixes currencies", () => {
    const totals = accountingTotals([
      row({ currency: "HUF", netAmount: 100, vatAmount: 27, grossAmount: 127 }),
      row({ currency: "EUR", netAmount: 10, vatAmount: 2, grossAmount: 12 }),
      row({ currency: "HUF", netAmount: 200, vatAmount: 54, grossAmount: 254 }),
    ]);
    expect(totals).toEqual([
      { currency: "EUR", net: 10, vat: 2, gross: 12 },
      { currency: "HUF", net: 300, vat: 81, gross: 381 },
    ]);
  });

  it("leaves a withdrawn irat out of the sum", () => {
    const totals = accountingTotals([
      row({ grossAmount: 1000, netAmount: 1000, vatAmount: 0 }),
      row({ grossAmount: 9999, netAmount: 9999, vatAmount: 0, ervenytelen: true }),
    ]);
    expect(totals).toEqual([{ currency: "HUF", net: 1000, vat: 0, gross: 1000 }]);
  });

  it("does not book a dijbekero next to the invoice issued for it", () => {
    // The live July case: IKT/6-1 is the díjbekérő, IKT/6-2 the invoice
    // raised for it. Both are 16 975 HUF, and both are correctly filed —
    // but only the invoice is a számviteli bizonylat, so the cost is
    // 16 975, not 33 950.
    const totals = accountingTotals([
      row({ docKind: "dijbekero", netAmount: 16975, vatAmount: 0, grossAmount: 16975 }),
      row({ docKind: "szamla", netAmount: 16975, vatAmount: 0, grossAmount: 16975 }),
    ]);
    expect(totals).toEqual([{ currency: "HUF", net: 16975, vat: 0, gross: 16975 }]);
  });

  it("counts a nyugta, which is a bizonylat", () => {
    const totals = accountingTotals([
      row({ docKind: "nyugta", netAmount: 100, vatAmount: 27, grossAmount: 127 }),
    ]);
    expect(totals).toEqual([{ currency: "HUF", net: 100, vat: 27, gross: 127 }]);
  });

  it("ignores an irat that carries no amount at all", () => {
    // A szállítólevél has no currency either, so it would otherwise open a
    // currency group of pure zeroes.
    const totals = accountingTotals([
      row({ docKind: "szallitolevel", netAmount: null, vatAmount: null, grossAmount: null, currency: null }),
    ]);
    expect(totals).toEqual([]);
  });

  it("does not drift when adding many fractional amounts", () => {
    const rows = Array.from({ length: 10 }, () =>
      row({ netAmount: 0.1, vatAmount: 0, grossAmount: 0.1 })
    );
    expect(accountingTotals(rows)[0].gross).toBe(1);
  });
});

describe("bookkeeping flag", () => {
  it("marks each row so the bookkeeper can filter in Excel", () => {
    const csv = toCsv([
      row({ iktatoszam: "IKT/6-1/2026", docKind: "dijbekero" }),
      row({ iktatoszam: "IKT/6-2/2026", docKind: "szamla" }),
    ]);
    const [, dijbekero, szamla] = csv.split("\r\n");
    expect(dijbekero).toContain('"nem"');
    expect(szamla).toContain('"igen"');
  });

  it("says nem for a withdrawn invoice, which is the right kind but not bookable", () => {
    const csv = toCsv([row({ docKind: "szamla", ervenytelen: true })]);
    expect(csv.split("\r\n")[1]).toContain('"nem";"ÉRVÉNYTELENÍTVE"');
  });

  // The invariant that matters: whatever the column claims, adding those rows
  // up in Excel must land on the number the app shows. The live July export
  // broke this — a withdrawn invoice was flagged "igen", so the column summed
  // to 520 730 while the header said 507 132.
  it("agrees with the total: summing the igen rows reproduces it", () => {
    const rows = [
      row({ iktatoszam: "IKT/1-1/2026", docKind: "szamla", netAmount: 950, vatAmount: 256.5, grossAmount: 1207 }),
      row({ iktatoszam: "IKT/2-1/2026", docKind: "szamla", netAmount: 385000, vatAmount: 103950, grossAmount: 488950 }),
      row({ iktatoszam: "IKT/3-1/2026", docKind: "szallitolevel", netAmount: null, vatAmount: null, grossAmount: null, currency: null }),
      row({ iktatoszam: "IKT/4-1/2026", docKind: "dijbekero", netAmount: 13598, vatAmount: 0, grossAmount: 13598, ervenytelen: true }),
      row({ iktatoszam: "IKT/5-1/2026", docKind: "szamla", netAmount: 13598, vatAmount: 0, grossAmount: 13598, ervenytelen: true }),
      row({ iktatoszam: "IKT/6-1/2026", docKind: "dijbekero", netAmount: 16975, vatAmount: 0, grossAmount: 16975 }),
      row({ iktatoszam: "IKT/6-2/2026", docKind: "szamla", netAmount: 16975, vatAmount: 0, grossAmount: 16975 }),
    ];

    const grossColumn = COLUMNS.findIndex((c) => c.header === "Bruttó");
    const bookableColumn = COLUMNS.findIndex((c) => c.header === "Könyvelendő");

    const fromCsv = toCsv(rows)
      .split("\r\n")
      .slice(1)
      .filter((line) => line !== "")
      .map((line) => line.split(CSV_SEPARATOR))
      .filter((cells) => cells[bookableColumn] === '"igen"')
      .reduce((sum, cells) => sum + Number(cells[grossColumn].replace(",", ".")), 0);

    expect(fromCsv).toBe(accountingTotals(rows)[0].gross);
    expect(fromCsv).toBe(507132);
  });
});
