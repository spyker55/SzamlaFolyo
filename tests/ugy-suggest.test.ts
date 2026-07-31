import { describe, expect, it } from "vitest";
import {
  normalizeCompanyName,
  suggestUgy,
  ugyLabel,
  type UgyCandidate,
} from "@/lib/iktatas/ugy-suggest";

describe("partner name normalization", () => {
  it("sees through the legal form and its punctuation", () => {
    // The real pair that motivated this: one Slovak supplier, two partner rows,
    // because neither document carried a Hungarian tax number.
    expect(normalizeCompanyName("Websupport s. r. o.")).toBe(
      normalizeCompanyName("Websupport S.R.O.")
    );
    expect(normalizeCompanyName("Nethely Kft.")).toBe(normalizeCompanyName("NETHELY KFT"));
  });

  it("strips Hungarian accents", () => {
    expect(normalizeCompanyName("Kovács Épületgépészet Kft.")).toBe("kovacsepuletgepeszet");
  });

  it("keeps genuinely different names apart", () => {
    expect(normalizeCompanyName("Nethely Kft.")).not.toBe(normalizeCompanyName("Stavmat Zrt."));
  });

  it("never strips a name down to nothing", () => {
    expect(normalizeCompanyName("Kft")).toBe("kft");
    expect(normalizeCompanyName(null)).toBe("");
  });
});

// The ügy opened by the díjbekérő, exactly as it stands in the database.
const dijbekeroUgy: UgyCandidate = {
  id: "ugy-4",
  prefix: "IKT",
  foszam: 4,
  ev: 2026,
  targy: "Hosting szolgáltatás - 2026. augusztus",
  partnerNames: ["Websupport s. r. o."],
  documents: [{ docKind: "dijbekero", grossAmount: 13598, currency: "HUF" }],
};

const masikUgy: UgyCandidate = {
  id: "ugy-2",
  prefix: "IKT",
  foszam: 2,
  ev: 2026,
  targy: "Kazáncsere és beüzemelés",
  partnerNames: ["Kovács Épületgépészet Kft."],
  documents: [{ docKind: "szamla", grossAmount: 488950, currency: "HUF" }],
};

const szamla = {
  partnerName: "Websupport s. r. o.",
  grossAmount: 13598,
  currency: "HUF",
  docKind: "szamla",
};

describe("ügy suggestion", () => {
  it("finds the díjbekérő's ügy for the invoice that answers it", () => {
    const [first, ...rest] = suggestUgy(szamla, [dijbekeroUgy, masikUgy]);
    expect(first.ugyId).toBe("ugy-4");
    expect(first.reason).toContain("díjbekérő");
    expect(rest).toHaveLength(0);
  });

  it("matches across two partner rows for the same company", () => {
    // The invoice's own partner row is a different id with a different
    // spelling; only the name can bridge them.
    const suggestions = suggestUgy({ ...szamla, partnerName: "WEBSUPPORT S.R.O." }, [dijbekeroUgy]);
    expect(suggestions).toHaveLength(1);
  });

  it("refuses to suggest on the partner alone", () => {
    // Next month's hosting invoice: same supplier, different amount. Offering
    // last month's ügy here would be wrong more often than right.
    expect(suggestUgy({ ...szamla, grossAmount: 14200 }, [dijbekeroUgy])).toEqual([]);
  });

  it("refuses to suggest on the amount alone", () => {
    expect(suggestUgy({ ...szamla, partnerName: "Más Cég Kft." }, [dijbekeroUgy])).toEqual([]);
  });

  it("does not match the same number in another currency", () => {
    expect(suggestUgy({ ...szamla, currency: "EUR" }, [dijbekeroUgy])).toEqual([]);
  });

  it("says nothing when the document has no amount yet", () => {
    expect(suggestUgy({ ...szamla, grossAmount: null }, [dijbekeroUgy])).toEqual([]);
    expect(suggestUgy({ ...szamla, partnerName: null }, [dijbekeroUgy])).toEqual([]);
  });

  it("tolerates rounding, not a different amount", () => {
    expect(suggestUgy({ ...szamla, grossAmount: 13598.004 }, [dijbekeroUgy])).toHaveLength(1);
    expect(suggestUgy({ ...szamla, grossAmount: 13599 }, [dijbekeroUgy])).toEqual([]);
  });

  it("ranks the díjbekérő match above a plain one and caps the list", () => {
    const plain: UgyCandidate[] = [1, 2, 3, 5].map((foszam) => ({
      ...dijbekeroUgy,
      id: `ugy-${foszam}`,
      foszam,
      documents: [{ docKind: "szamla", grossAmount: 13598, currency: "HUF" }],
    }));

    const suggestions = suggestUgy(szamla, [...plain, dijbekeroUgy]);
    expect(suggestions).toHaveLength(3);
    expect(suggestions[0].ugyId).toBe("ugy-4");
  });

  it("labels an ügy the way the iktatókönyv writes it", () => {
    expect(ugyLabel(dijbekeroUgy)).toBe("IKT/4/2026 — Hosting szolgáltatás - 2026. augusztus");
    expect(ugyLabel({ ...dijbekeroUgy, targy: null })).toBe("IKT/4/2026");
  });
});
