import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  budapestEv,
  irattariJel,
  megorzes,
  megorzesSzoveg,
  NEM_SELEJTEZHETO_JEL,
  validateTetel,
  type TetelInput,
} from "@/lib/irattar/terv";

const MIGRATION = join(
  process.cwd(),
  "supabase",
  "migrations",
  "20260801000022_irattari_terv.sql"
);

const MOST = 2026;

describe("az irattári jel", () => {
  it("a tételszámot és az őrzési időt írja ki", () => {
    expect(irattariJel("P-2", 8)).toBe("P-2/8");
  });

  it("a nem selejtezhetőt NS-sel jelöli, nem üresen", () => {
    expect(irattariJel("M-1", null)).toBe(`M-1/${NEM_SELEJTEZHETO_JEL}`);
  });
});

describe("a megőrzési idő számolása", () => {
  // "8 évig kell megőrizni" a 2026-ban lezárt ügyre 2027-től 2034-ig tart —
  // nyolc teljes év —, tehát 2034. december 31-ig. Ugyanez a számolás áll a
  // 2024-es bizonylatokra, amiket 2032. december 31-ig kell megőrizni.
  it("a lezárás évétől számol, és az utolsó évet adja vissza", () => {
    const m = megorzes(2026, 8, MOST);
    expect(m.utolsoEv).toBe(2034);
    expect(m.selejtezhetoEv).toBe(2035);
    expect(m.allapot).toBe("megorzendo");
    expect(m.hatralevoEv).toBe(9);
  });

  it("a megőrzési idő lejárta utáni évtől selejtezhető", () => {
    expect(megorzes(2016, 8, 2024).allapot).toBe("megorzendo");
    expect(megorzes(2016, 8, 2025).allapot).toBe("selejtezheto");
  });

  // Ez a fájl legfontosabb sora. Az üres őrzési idő nem hiányzó adat, hanem a
  // legerősebb érték, amit a mező felvehet.
  it("a null őrzési idő nem selejtezhetőt jelent, nem azonnal selejtezhetőt", () => {
    const m = megorzes(1990, null, MOST);
    expect(m.allapot).toBe("nem_selejtezheto");
    expect(m.selejtezhetoEv).toBeNull();
    expect(megorzesSzoveg(m)).toBe("Nem selejtezhető.");
  });

  it("a besorolatlan ügyről nem állít semmit", () => {
    const m = megorzes(1990, null, MOST, false);
    expect(m.allapot).toBe("besorolatlan");
    expect(megorzesSzoveg(m)).toContain("megmarad");
  });

  it("a nyitott ügynél el sem indul az óra", () => {
    const m = megorzes(null, 8, MOST);
    expect(m.allapot).toBe("nyitva");
    expect(m.utolsoEv).toBeNull();
  });

  it("magyarul mondja meg, meddig kell tartani", () => {
    expect(megorzesSzoveg(megorzes(2026, 8, MOST))).toBe(
      "2034. december 31-ig őrzendő, utána selejtezhető."
    );
    expect(megorzesSzoveg(megorzes(2010, 5, MOST))).toBe(
      "2016. január 1. óta selejtezhető."
    );
  });
});

describe("a lezárás éve", () => {
  // Egy szilveszter éjjel lezárt ügy a magyar naptár szerinti évhez tartozik.
  // UTC-ben számolva egy évvel korábbra esne, és a megőrzés egy évvel hamarabb
  // járna le — pont a rossz irányba.
  it("budapesti idő szerint dönt, nem UTC szerint", () => {
    expect(budapestEv("2026-12-31T23:30:00.000Z")).toBe(2027);
    expect(budapestEv("2026-12-31T22:00:00.000Z")).toBe(2026);
  });

  it("a hiányzó dátumra nem talál ki évet", () => {
    expect(budapestEv(null)).toBeNull();
    expect(budapestEv("")).toBeNull();
    expect(budapestEv("nem dátum")).toBeNull();
  });
});

describe("a tétel érvényessége", () => {
  const base: TetelInput = {
    tetelszam: "P-9",
    nev: "Teszt tétel",
    orzesiIdoEv: 8,
    jogszabaly: "",
    megjegyzes: "",
  };

  it("elfogadja az üres őrzési időt, mert az a nem selejtezhető", () => {
    expect(validateTetel({ ...base, orzesiIdoEv: null })).toBeNull();
  });

  it("nem enged nullát vagy negatívat", () => {
    expect(validateTetel({ ...base, orzesiIdoEv: 0 })).toContain("1 és 100");
    expect(validateTetel({ ...base, orzesiIdoEv: -1 })).toContain("1 és 100");
  });

  it("megköveteli a tételszámot és a megnevezést", () => {
    expect(validateTetel({ ...base, tetelszam: "  " })).toContain("tételszám");
    expect(validateTetel({ ...base, nev: "" })).toContain("megnevezés");
  });
});

describe("az alapértelmezett irattári terv", () => {
  const sql = readFileSync(MIGRATION, "utf8");

  // A seed egy sora:
  //   (p_company_id, 'P-2', 'Bejövő számlák…', 8, 'Szt. 169. § (2)', 'megjegyzés', 40),
  const rows = [
    ...sql.matchAll(
      /\(p_company_id, '([^']+)', '([^']+)', (null|\d+),\s*(null|'[^']*'),\s*(null|'[^']*'), (\d+)\)/g
    ),
  ].map((m) => ({
    tetelszam: m[1],
    nev: m[2],
    orzesiIdo: m[3] === "null" ? null : Number(m[3]),
    jogszabaly: m[4] === "null" ? null : m[4],
    megjegyzes: m[5] === "null" ? null : m[5],
  }));

  it("tizenhárom tételt vet be", () => {
    expect(rows).toHaveLength(13);
  });

  it("minden tételszám egyedi", () => {
    expect(new Set(rows.map((r) => r.tetelszam)).size).toBe(rows.length);
  });

  // A számviteli bizonylat 8 éve a Szt. 169. § (2)-ből jön; ha ez a szám
  // elcsúszik, a rendszer törvénysértően korán ajánlana selejtezést.
  it("a számviteli bizonylatokat 8 évig őrzi", () => {
    for (const tetelszam of ["P-1", "P-2", "P-3", "P-4"]) {
      const row = rows.find((r) => r.tetelszam === tetelszam)!;
      expect(row, `hiányzik: ${tetelszam}`).toBeTruthy();
      expect(row.orzesiIdo, `${tetelszam} őrzési ideje`).toBe(8);
      expect(row.jogszabaly).toContain("169");
    }
  });

  // A Tny. 99/A. § a nyugdíjkorhatárhoz köti a munkaügyi iratok megőrzését, és
  // az az ügy lezárásából nem számolható. Bármilyen szám itt találgatás lenne
  // a megsemmisítés irányába.
  it("a munkaügyi iratokat nem selejtezhetőnek jelöli", () => {
    for (const tetelszam of ["M-1", "M-2"]) {
      const row = rows.find((r) => r.tetelszam === tetelszam)!;
      expect(row.orzesiIdo, `${tetelszam}`).toBeNull();
      expect(row.jogszabaly).toContain("Tny");
    }
  });

  it("minden őrzési idő az adatbázis által elfogadott tartományban van", () => {
    for (const row of rows) {
      if (row.orzesiIdo === null) continue;
      expect(row.orzesiIdo).toBeGreaterThanOrEqual(1);
      expect(row.orzesiIdo).toBeLessThanOrEqual(100);
    }
  });

  // Egy megőrzési idő indoklás nélkül vélemény. Ahol nincs egységes
  // jogszabály (H-1, J-1, A-1), ott a megjegyzés mondja meg, mire alapoztuk.
  it("minden tétel megmondja, mire alapozza a határidőt", () => {
    for (const row of rows) {
      expect(
        row.jogszabaly !== null || row.megjegyzes !== null,
        `${row.tetelszam} se jogszabályt, se indoklást nem ad`
      ).toBe(true);
    }
  });
});
