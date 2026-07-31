import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  checkTaxNumber,
  formatTaxNumber,
  isHuTaxNumber,
  isValidHuTaxNumber,
  normalizePartnerName,
  taxNumberCore,
} from "@/lib/partner/identity";
import {
  bankAccountKind,
  bankAccountWarning,
  checkBankAccount,
  formatBankAccount,
  huBlocksLookRight,
  isValidIban,
  normalizeBankAccount,
} from "@/lib/partner/bank-account";
import {
  baseName,
  findDuplicateCandidates,
  legalForm,
  mergeBlockedReason,
} from "@/lib/partner/duplicates";

const MIGRATIONS_DIR = join(process.cwd(), "supabase", "migrations");

function allMigrations(): string {
  return readdirSync(MIGRATIONS_DIR)
    .filter((f) => f.endsWith(".sql"))
    .sort()
    .map((f) => readFileSync(join(MIGRATIONS_DIR, f), "utf8"))
    .join("\n");
}

describe("partner name normalization mirrors the database", () => {
  it("folds exactly the letters translate() folds", () => {
    // app.normalize_company_name() is what resolves partners on iktatás and
    // what the unique index is built on. If this drifts, the screen and the
    // database stop agreeing about which rows are the same company.
    const sql = allMigrations();
    const match = /translate\(\s*lower\([\s\S]*?\),\s*'([^']*)',\s*'([^']*)'/.exec(sql);
    expect(match, "app.normalize_company_name() not found in the migrations").not.toBeNull();

    const [, accented, plain] = match!;
    for (let i = 0; i < accented.length; i++) {
      expect(normalizePartnerName(accented[i])).toBe(plain[i].toLowerCase());
    }
  });

  it("drops everything that is not a-z0-9, accents included", () => {
    expect(normalizePartnerName("Nethely Kft.")).toBe("nethelykft");
    expect(normalizePartnerName("Websupport s. r. o.")).toBe("websupportsro");
    expect(normalizePartnerName("Kovács Épületgépészet Kft.")).toBe("kovacsepuletgepeszetkft");
  });

  it("removes a letter it cannot fold rather than guessing at it", () => {
    // translate() only knows the nine Hungarian vowels, so a Czech háček is
    // stripped, not turned into "s". Normalizing with NFD here would fold it
    // and quietly disagree with the index.
    expect(normalizePartnerName("Škoda")).toBe("koda");
  });

  it("is empty for a name made of nothing but punctuation", () => {
    expect(normalizePartnerName("— . —")).toBe("");
    expect(normalizePartnerName(null)).toBe("");
  });
});

describe("adószám", () => {
  // Public data, taken from three invoices actually filed in this register.
  // They are the evidence that the check-digit weighting below is the real
  // one and not a remembered guess.
  const REAL = ["23358005-2-43", "24681353-2-13", "11187433-2-44"];

  it("accepts the adószám on the invoices in this register", () => {
    for (const t of REAL) {
      expect(isHuTaxNumber(t), t).toBe(true);
      expect(isValidHuTaxNumber(t), t).toBe(true);
      expect(checkTaxNumber(t).ok, t).toBe(true);
    }
  });

  it("catches a single mistyped digit", () => {
    // 23358005 -> 23358006: the check digit no longer matches.
    expect(isValidHuTaxNumber("23358006-2-43")).toBe(false);
    const check = checkTaxNumber("23358006-2-43");
    expect(check.ok).toBe(false);
    expect(check.ok === false && check.message).toContain("ellenőrző");
  });

  it("compares taxpayers by törzsszám, not by the whole number", () => {
    // The ÁFA code changes when a company joins a VAT group; the megyekód
    // follows the seat. Neither makes it a different taxpayer.
    expect(taxNumberCore("23358005-2-43")).toBe("23358005");
    expect(taxNumberCore("23358005-4-43")).toBe("23358005");
    expect(taxNumberCore("23358005-2-43")).toBe(taxNumberCore("233580054 43"));
    expect(taxNumberCore("11187433-2-44")).not.toBe(taxNumberCore("23358005-2-43"));
  });

  it("compares anything that is not an 11-digit number whole", () => {
    expect(taxNumberCore("SK2020317068")).toBe("SK2020317068");
    expect(taxNumberCore("sk 2020317068")).toBe("SK2020317068");
    expect(taxNumberCore("")).toBeNull();
    expect(taxNumberCore(null)).toBeNull();
  });

  it("mirrors app.tax_number_core()", () => {
    const sql = allMigrations();
    expect(sql).toContain("create or replace function app.tax_number_core");
    // The 8 is the törzsszám length in both places; a change on one side
    // without the other would split the register in two.
    expect(sql).toMatch(/left\(regexp_replace\(p_tax_number, '\[\^0-9\]', '', 'g'\), 8\)/);
  });

  it("lets a foreign registration number through untouched", () => {
    expect(checkTaxNumber("SK2020317068").ok).toBe(true);
    expect(formatTaxNumber("SK2020317068")).toBe("SK2020317068");
    expect(formatTaxNumber("23358005243")).toBe("23358005-2-43");
    expect(formatTaxNumber(null)).toBe("");
  });

  it("refuses a number of the wrong length", () => {
    expect(checkTaxNumber("1234").ok).toBe(false);
    expect(checkTaxNumber("").ok).toBe(true);
  });
});

describe("bankszámlaszám", () => {
  it("validates an IBAN by the mod-97 rule", () => {
    expect(isValidIban("GB82 WEST 1234 5698 7654 32")).toBe(true);
    expect(isValidIban("HU42 1177 3016 1111 1018 0000 0000")).toBe(true);
    expect(isValidIban("DE89 3704 0044 0532 0130 00")).toBe(true);
    expect(isValidIban("SK31 1200 0000 1987 4263 7541")).toBe(true);
  });

  it("catches a tampered IBAN check number", () => {
    expect(isValidIban("GB82 WEST 1234 5698 7654 33")).toBe(false);
    const check = checkBankAccount("GB82 WEST 1234 5698 7654 33");
    expect(check.ok).toBe(false);
  });

  it("reads the two Hungarian shapes and the IBAN apart", () => {
    expect(bankAccountKind("11773016-11111018")).toBe("hu");
    expect(bankAccountKind("11773016-11111018-00000000")).toBe("hu");
    expect(bankAccountKind("HU42117730161111101800000000")).toBe("iban");
    expect(bankAccountKind("12345")).toBe("ismeretlen");
    expect(bankAccountKind("")).toBe("ismeretlen");
  });

  it("checks the GIRO blocks against the same weighting the adószám uses", () => {
    // The BBAN of the published Hungarian IBAN above, whose two non-trivial
    // blocks both satisfy the rule — the reason this check exists at all.
    expect(huBlocksLookRight("11773016-11111018-00000000")).toBe(true);
    expect(huBlocksLookRight("11773016-11111018")).toBe(true);
    expect(huBlocksLookRight("12345678-12345678")).toBe(false);
  });

  it("warns about a bad check digit but never refuses the account", () => {
    // A wrong validator must not stand between the user and a real transfer.
    expect(checkBankAccount("12345678-12345678").ok).toBe(true);
    expect(bankAccountWarning("12345678-12345678")).toContain("ellenőrző");
    expect(bankAccountWarning("11773016-11111018")).toBeNull();
    expect(bankAccountWarning("HU42117730161111101800000000")).toBeNull();
  });

  it("stores the digits and prints the separators", () => {
    expect(normalizeBankAccount("11773016-11111018")).toBe("1177301611111018");
    expect(formatBankAccount("1177301611111018")).toBe("11773016-11111018");
    expect(formatBankAccount("117730161111101800000000")).toBe(
      "11773016-11111018-00000000"
    );
    expect(formatBankAccount("HU42117730161111101800000000")).toBe(
      "HU42 1177 3016 1111 1018 0000 0000"
    );
    expect(formatBankAccount(null)).toBe("");
  });

  it("refuses a number that is neither shape", () => {
    const check = checkBankAccount("123");
    expect(check.ok).toBe(false);
    expect(checkBankAccount("").ok).toBe(true);
  });
});

describe("duplicate candidates", () => {
  const p = (id: string, name: string, taxNumber: string | null = null) => ({
    id,
    name,
    taxNumber,
  });

  it("never pairs two different taxpayers", () => {
    // The rule the whole feature stands on: merge_partner() refuses this, so
    // the screen must not offer it either.
    const pairs = findDuplicateCandidates([
      p("a", "Nethely Kft.", "23358005-2-43"),
      p("b", "Nethely Kft.", "11187433-2-44"),
    ]);
    expect(pairs).toEqual([]);
  });

  it("pairs the same törzsszám written with a different ÁFA-kód", () => {
    const pairs = findDuplicateCandidates([
      p("a", "Nethely Kft.", "23358005-2-43"),
      p("b", "Nethely Korlátolt Felelősségű Társaság", "23358005-4-43"),
    ]);
    expect(pairs).toHaveLength(1);
    expect(pairs[0].strength).toBe("biztos");
  });

  it("keeps Nethely Kft. and Nethely Bt. apart", () => {
    // 20260730000013 named this case: the legal form is the difference
    // between two companies, so it is compared, not stripped.
    expect(findDuplicateCandidates([p("a", "Nethely Kft."), p("b", "Nethely Bt.")])).toEqual(
      []
    );
  });

  it("pairs the same name written differently", () => {
    const pairs = findDuplicateCandidates([
      p("a", "Websupport s. r. o."),
      p("b", "WEBSUPPORT S.R.O."),
    ]);
    expect(pairs).toHaveLength(1);
    expect(pairs[0].strength).toBe("valoszinu");
  });

  it("pairs a name with the same name plus a legal form", () => {
    const pairs = findDuplicateCandidates([p("a", "Websupport"), p("b", "Websupport s. r. o.")]);
    expect(pairs).toHaveLength(1);
    expect(pairs[0].strength).toBe("valoszinu");
  });

  it("offers a prefix match only as a possibility, and only from six letters", () => {
    const long = findDuplicateCandidates([
      p("a", "Stavmat"),
      p("b", "Stavmat Építőanyag"),
    ]);
    expect(long).toHaveLength(1);
    expect(long[0].strength).toBe("lehetseges");

    const short = findDuplicateCandidates([p("a", "Bau"), p("b", "Bauhaus")]);
    expect(short).toEqual([]);
  });

  it("sorts the certain pairs to the top", () => {
    const pairs = findDuplicateCandidates([
      p("a", "Stavmat"),
      p("b", "Stavmat Építőanyag"),
      p("c", "Nethely Kft.", "23358005-2-43"),
      p("d", "Nethely Zrt.", "23358005-4-43"),
    ]);
    expect(pairs[0].strength).toBe("biztos");
  });

  it("reads the legal form off a name and never eats the whole name", () => {
    expect(legalForm("Nethely Kft.")).toBe("kft");
    expect(legalForm("Websupport s. r. o.")).toBe("sro");
    expect(legalForm("Stavmat")).toBeNull();
    // A company literally called "Kft." keeps its name.
    expect(legalForm("Kft.")).toBeNull();
    expect(baseName("Kft.")).toBe("kft");
    expect(baseName("Nethely Kft.")).toBe("nethely");
  });

  it("explains the refusal the database would give", () => {
    const nethely = p("a", "Nethely Kft.", "23358005-2-43");
    const hero = p("b", "Delivery Hero Hungary Kft.", "11187433-2-44");
    expect(mergeBlockedReason(nethely, hero)).toContain("két külön cég");
    expect(mergeBlockedReason(nethely, nethely)).toContain("önmagába");
    expect(mergeBlockedReason(nethely, p("c", "Nethely Zrt.", "23358005-4-43"))).toBeNull();
    expect(mergeBlockedReason(nethely, p("c", "Valaki Más"))).toBeNull();
  });
});
