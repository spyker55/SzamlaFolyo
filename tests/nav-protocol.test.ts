import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it, beforeAll } from "vitest";
import {
  headerTimestamp,
  isValidRequestId,
  newRequestId,
  passwordHash,
  requestSignature,
  signatureTimestamp,
} from "@/lib/nav/signature";
import { el, escapeXml, parseXml, txt } from "@/lib/nav/xml";
import { buildRequest, navErrorMessage, navRequest, NavError } from "@/lib/nav/client";
import {
  digestRequestBody,
  invoiceNumberKey,
  readDigestPage,
  splitDateRange,
} from "@/lib/nav/query";
import { navSoftware } from "@/lib/nav/config";
import { decryptSecret, encryptSecret } from "@/lib/nav/secret";

const AT = new Date("2026-08-03T09:15:04.123Z");

const CREDENTIALS = {
  taxNumber: "12345676",
  login: "teszt-technikai",
  password: "Teszt-Jelszo1",
  signKey: "sign-key-123",
  environment: "test" as const,
};

const SOFTWARE = {
  softwareId: "SZAMLAFOLYO-000001",
  softwareName: "Szamlafolyo",
  softwareOperation: "ONLINE_SERVICE" as const,
  softwareMainVersion: "1.0",
  softwareDevName: "Szamlafolyo",
  softwareDevContact: "fejlesztes@szamlafolyo.hu",
  softwareDevCountryCode: "HU",
  softwareDevTaxNumber: "12345676",
};

// A queryInvoiceDigest answer in the shape NAV actually returns it: the common
// elements under a generated ns2: prefix, the OSA elements unprefixed.
const DIGEST_RESPONSE = `<?xml version="1.0" encoding="UTF-8"?>
<QueryInvoiceDigestResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api" xmlns:ns2="http://schemas.nav.gov.hu/NTCA/1.0/common">
  <ns2:header><ns2:requestId>RID1</ns2:requestId><ns2:timestamp>2026-08-03T09:15:05.000Z</ns2:timestamp><ns2:requestVersion>3.0</ns2:requestVersion></ns2:header>
  <ns2:result><ns2:funcCode>OK</ns2:funcCode></ns2:result>
  <software><softwareId>SZAMLAFOLYO-000001</softwareId></software>
  <invoiceDigestResult>
    <currentPage>1</currentPage>
    <availablePage>2</availablePage>
    <invoiceDigest>
      <invoiceNumber>2026/00123</invoiceNumber>
      <batchIndex>1</batchIndex>
      <invoiceOperation>CREATE</invoiceOperation>
      <invoiceCategory>NORMAL</invoiceCategory>
      <invoiceIssueDate>2026-07-03</invoiceIssueDate>
      <supplierTaxNumber>12345676</supplierTaxNumber>
      <supplierName>Nethely Kft. &amp; Társa</supplierName>
      <customerTaxNumber>23456787</customerTaxNumber>
      <paymentDate>2026-07-17</paymentDate>
      <source>XML</source>
      <invoiceDeliveryDate>2026-07-03</invoiceDeliveryDate>
      <currency>HUF</currency>
      <invoiceNetAmount>100000</invoiceNetAmount>
      <invoiceNetAmountHUF>100000</invoiceNetAmountHUF>
      <invoiceVatAmount>27000</invoiceVatAmount>
      <invoiceVatAmountHUF>27000</invoiceVatAmountHUF>
      <transactionId>4Y3F6L1A2B3C4D5E</transactionId>
      <index>2</index>
      <completenessIndicator>false</completenessIndicator>
      <insDate>2026-07-03T09:12:33.123Z</insDate>
    </invoiceDigest>
  </invoiceDigestResult>
</QueryInvoiceDigestResponse>`;

const ERROR_RESPONSE = `<?xml version="1.0" encoding="UTF-8"?>
<GeneralErrorResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api" xmlns:ns2="http://schemas.nav.gov.hu/NTCA/1.0/common">
  <ns2:result>
    <ns2:funcCode>ERROR</ns2:funcCode>
    <ns2:errorCode>INVALID_SECURITY_USER</ns2:errorCode>
    <ns2:message>Technical user validation failed</ns2:message>
  </ns2:result>
  <technicalValidationMessages>
    <validationResultCode>ERROR</validationResultCode>
    <validationErrorCode>SECURITY_ERROR</validationErrorCode>
    <message>Invalid request signature</message>
  </technicalValidationMessages>
</GeneralErrorResponse>`;

describe("a kérés aláírása", () => {
  // Hardcoded rather than recomputed with the same crypto call the module
  // makes: a test that hashes the input itself would agree with any algorithm
  // the module happened to pick, including the wrong one.
  it("a jelszót SHA-512-vel, nagybetűs hexben adja meg", () => {
    expect(passwordHash("Teszt-Jelszo1")).toBe(
      "6B2277268B3C58EA4C1835A1EAC474C6560F5C80F3BEA4194FEF336289ED3278" +
        "383FF07A19167033C161D2FB2BD0289682E4C243C73CB3A57CFEAD9E22729A25"
    );
  });

  // API 2.0 signed with SHA-512 and 3.0 signs with SHA-3. Every value in the
  // request is the same, so the mistake shows up only as INVALID_SECURITY_USER
  // — which is also what a wrong password looks like. Pinning the vector is
  // the only way this stays diagnosed.
  it("a kérést SHA3-512-vel írja alá, nem SHA-512-vel", () => {
    const signature = requestSignature("SZF20260803091504ABCDEFGH", AT, "sign-key-123");
    expect(signature).toBe(
      "9D16F1AA6E1600255AF33B71C37EB3A6124E3D70A10794E15BCA48804B3A4A2D" +
        "F8E80230920E6B86214033A6BF896412B74F5D851679207045376CDAD5FCAD47"
    );
    expect(signature.startsWith("0E9C29196AD37DCE")).toBe(false);
  });

  it("ugyanazt a másodpercet írja a fejlécbe és az aláírásba", () => {
    expect(headerTimestamp(AT)).toBe("2026-08-03T09:15:04.123Z");
    expect(signatureTimestamp(AT)).toBe("20260803091504");
  });

  it("a NAV által elfogadott alakú azonosítót ad", () => {
    const id = newRequestId(AT);
    expect(id).toHaveLength(25);
    expect(isValidRequestId(id)).toBe(true);
    expect(isValidRequestId("nem jó azonosító")).toBe(false);
  });
});

describe("a kérés összeállítása", () => {
  const built = buildRequest({
    operation: "QueryInvoiceDigest",
    credentials: CREDENTIALS,
    software: SOFTWARE,
    body: digestRequestBody({ page: 1, direction: "bejovo", from: "2026-07-01", to: "2026-07-31" }),
    at: AT,
    requestId: "SZF20260803091504ABCDEFGH",
  });

  // The XSD declares a sequence, so a request with every field correct but in
  // the wrong order comes back as SCHEMA_VIOLATION.
  it("a fejléc, a felhasználó és a szoftver ebben a sorrendben megy", () => {
    const order = ["<common:header>", "<common:user>", "<software>", "<page>"].map((t) =>
      built.xml.indexOf(t)
    );
    expect(order.every((i) => i >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
  });

  it("az aláírás ugyanahhoz az azonosítóhoz tartozik, amit elküld", () => {
    const root = parseXml(built.xml);
    const user = el(root, "user");
    expect(txt(el(root, "header"), "requestId")).toBe(built.requestId);
    expect(txt(user, "requestSignature")).toBe(
      requestSignature(built.requestId, AT, CREDENTIALS.signKey)
    );
    expect(el(user, "requestSignature")?.attrs.cryptoType).toBe("SHA3-512");
    expect(el(user, "passwordHash")?.attrs.cryptoType).toBe("SHA-512");
  });

  it("a jelszót soha nem küldi el nyersen", () => {
    expect(built.xml).not.toContain(CREDENTIALS.password);
    expect(built.xml).not.toContain(CREDENTIALS.signKey);
  });

  it("az irányt a NAV szótárára fordítja", () => {
    expect(built.xml).toContain("<invoiceDirection>INBOUND</invoiceDirection>");
    expect(
      digestRequestBody({ page: 1, direction: "kimeno", from: "2026-07-01", to: "2026-07-31" })
    ).toContain("OUTBOUND");
  });
});

// The integration reads and does not report. This is the one line of the
// design that a later "just add submitting" would quietly cross, so it is a
// test rather than a comment: a second adatszolgáltatás for an invoice
// somebody else already reported is a false submission under the company's
// tax number.
describe("az integráció iránya", () => {
  it("sehol nem hivatkozik beküldő műveletre", () => {
    const dir = join(process.cwd(), "src", "lib", "nav");
    for (const file of readdirSync(dir)) {
      const source = readFileSync(join(dir, file), "utf8");
      const code = source
        .split("\n")
        .filter((line) => !line.trimStart().startsWith("//") && !line.trimStart().startsWith("*"))
        .join("\n");
      expect(code, `${file} beküldő műveletre hivatkozik`).not.toMatch(
        /manageInvoice|manageAnnulment|tokenExchange/
      );
    }
  });
});

describe("az XML olvasása", () => {
  it("a névtérelőtagokat lehántja, hogy a common: és az ns2: ugyanaz legyen", () => {
    const root = parseXml(DIGEST_RESPONSE);
    expect(txt(el(root, "result"), "funcCode")).toBe("OK");
    expect(txt(el(root, "header"), "requestId")).toBe("RID1");
  });

  it("feloldja az entitásokat", () => {
    const root = parseXml(DIGEST_RESPONSE);
    const digest = el(root, "invoiceDigestResult", "invoiceDigest");
    expect(txt(digest, "supplierName")).toBe("Nethely Kft. & Társa");
  });

  it("a CDATA-t szövegként olvassa", () => {
    const root = parseXml("<a><b><![CDATA[Kft. & <Társa>]]></b></a>");
    expect(txt(root, "b")).toBe("Kft. & <Társa>");
  });

  // Egy elnéző értelmező itt csendben eldobna egy <invoiceDigest> elemet, és
  // az eldobott sor pontosan úgy néz ki, mint egy hiányzó számla. Inkább
  // hangosan álljon meg.
  it("elutasít mindent, amit nem ért", () => {
    expect(() => parseXml('<!DOCTYPE a [<!ENTITY x "y">]><a/>')).toThrow(/deklaráció/);
    expect(() => parseXml("<a>&ismeretlen;</a>")).toThrow(/ismeretlen entitás/);
    expect(() => parseXml("<a><b></c></a>")).toThrow(/nem illeszkedő/);
    expect(() => parseXml("<a><b></a>")).toThrow(/nem illeszkedő/);
    expect(() => parseXml("<a/><b/>")).toThrow(/több gyökérelem/);
    expect(() => parseXml("<a>")).toThrow(/lezáratlan elem/);
  });

  it("az írás és az olvasás egymás inverze", () => {
    const value = 'Kovács & Társa <Kft.> "A"';
    const root = parseXml(`<a>${escapeXml(value)}</a>`);
    expect(txt(root)).toBe(value);
  });
});

describe("a digest olvasása", () => {
  const page = readDigestPage(parseXml(DIGEST_RESPONSE), "bejovo");

  it("megmondja, hány lap van még", () => {
    expect(page.currentPage).toBe(1);
    expect(page.availablePage).toBe(2);
    expect(page.digests).toHaveLength(1);
  });

  it("a tranzakció és a sorszám azonosítja a bejelentést", () => {
    expect(page.digests[0].transactionKey).toBe("4Y3F6L1A2B3C4D5E:2");
  });

  it("bejövőnél a szállító a partner, kimenőnél a vevő", () => {
    expect(page.digests[0].partnerTaxCore).toBe("12345676");
    const kimeno = readDigestPage(parseXml(DIGEST_RESPONSE), "kimeno");
    expect(kimeno.digests[0].partnerTaxCore).toBe("23456787");
  });

  it("megőrzi a NAV saját sorát", () => {
    expect(page.digests[0].raw.invoiceNumber).toBe("2026/00123");
    expect(page.digests[0].raw.transactionId).toBe("4Y3F6L1A2B3C4D5E");
  });

  it("az üres válasz nulla lap, nem hiba", () => {
    const empty = readDigestPage(
      parseXml(
        '<QueryInvoiceDigestResponse><result><funcCode>OK</funcCode></result></QueryInvoiceDigestResponse>'
      ),
      "bejovo"
    );
    expect(empty.availablePage).toBe(0);
    expect(empty.digests).toEqual([]);
  });

  // Kis- és nagybetű meg a szóköz elgépelés, a perjel viszont a számla
  // számának a része: azt levágva két különböző számla eshetne egybe.
  it("a számlaszámot kisbetű és szóköz szerint hozza közös alakra", () => {
    expect(invoiceNumberKey(" 2026/00123 ")).toBe("2026/00123");
    expect(invoiceNumberKey("a-1/b")).toBe("A-1/B");
    expect(invoiceNumberKey(null)).toBe("");
  });
});

describe("az időszak felosztása", () => {
  it("35 napnál nem kér hosszabbat", () => {
    const windows = splitDateRange("2026-01-01", "2026-03-31");
    expect(windows.length).toBeGreaterThan(1);
    for (const w of windows) {
      const days =
        (Date.parse(`${w.to}T00:00:00Z`) - Date.parse(`${w.from}T00:00:00Z`)) / 86_400_000 + 1;
      expect(days).toBeLessThanOrEqual(35);
    }
  });

  it("hézag és átfedés nélkül fedi le a teljes időszakot", () => {
    const windows = splitDateRange("2026-01-01", "2026-03-31");
    expect(windows[0].from).toBe("2026-01-01");
    expect(windows[windows.length - 1].to).toBe("2026-03-31");
    for (let i = 1; i < windows.length; i++) {
      const prevEnd = Date.parse(`${windows[i - 1].to}T00:00:00Z`);
      expect(Date.parse(`${windows[i].from}T00:00:00Z`)).toBe(prevEnd + 86_400_000);
    }
  });

  it("a rövid időszak egyetlen ablak", () => {
    expect(splitDateRange("2026-07-01", "2026-07-10")).toEqual([
      { from: "2026-07-01", to: "2026-07-10" },
    ]);
  });

  it("a fordított időszakot nem kérdezi le", () => {
    expect(splitDateRange("2026-07-10", "2026-07-01")).toEqual([]);
  });
});

describe("a NAV hibái", () => {
  it("a hibaválaszt hibaként adja tovább, a kódjával együtt", async () => {
    const err = await navRequest({
      operation: "QueryTaxpayer",
      credentials: CREDENTIALS,
      software: SOFTWARE,
      body: "",
      transport: async () => ({ status: 400, text: ERROR_RESPONSE }),
    }).catch((e) => e);

    expect(err).toBeInstanceOf(NavError);
    expect((err as NavError).code).toBe("INVALID_SECURITY_USER");
    expect((err as NavError).details).toContain("Invalid request signature");
  });

  it("magyarul mondja meg, mit kell megnézni", () => {
    const message = navErrorMessage(new NavError("x", "INVALID_REQUEST_SIGNATURE"));
    expect(message).toContain("aláíró kulcs");
    expect(navErrorMessage(new NavError("x", "NETWORK"))).toContain("nem futott le");
  });

  it("a hálózati hiba nem tesz úgy, mintha lefutott volna", async () => {
    const err = await navRequest({
      operation: "QueryTaxpayer",
      credentials: CREDENTIALS,
      software: SOFTWARE,
      body: "",
      transport: async () => {
        throw new Error("fetch failed");
      },
    }).catch((e) => e);
    expect((err as NavError).code).toBe("NETWORK");
  });

  it("az értelmezhetetlen választ nem nézi sikernek", async () => {
    const err = await navRequest({
      operation: "QueryTaxpayer",
      credentials: CREDENTIALS,
      software: SOFTWARE,
      body: "",
      transport: async () => ({ status: 502, text: "<html>Bad gateway" }),
    }).catch((e) => e);
    expect((err as NavError).code).toBe("UNPARSEABLE");
  });
});

describe("a szoftverazonosítás", () => {
  it("a fejlesztő adószáma nélkül meg sem próbálja", () => {
    const result = navSoftware({});
    expect(result.ok).toBe(false);
  });

  it("a 18 karakteres azonosítót megköveteli", () => {
    const result = navSoftware({
      NAV_SOFTWARE_ID: "RÖVID",
      NAV_SOFTWARE_DEV_TAX_NUMBER: "12345676",
      NAV_SOFTWARE_DEV_CONTACT: "a@b.hu",
    });
    expect(result.ok).toBe(false);
  });

  it("a beállított értékekkel áll össze", () => {
    const result = navSoftware({
      NAV_SOFTWARE_DEV_TAX_NUMBER: "12345676",
      NAV_SOFTWARE_DEV_CONTACT: "fejlesztes@szamlafolyo.hu",
    });
    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.software.softwareId).toHaveLength(18);
      expect(result.software.softwareOperation).toBe("ONLINE_SERVICE");
    }
  });
});

describe("a titkok tárolása", () => {
  beforeAll(() => {
    process.env.NAV_SECRET_KEY = Buffer.alloc(32, 7).toString("base64");
  });

  const COMPANY = "11111111-1111-1111-1111-111111111111";
  const OTHER = "22222222-2222-2222-2222-222222222222";

  it("visszafejthető ugyanazzal a céggel", () => {
    const enc = encryptSecret("titkos-jelszó", COMPANY);
    expect(enc).not.toContain("titkos");
    expect(decryptSecret(enc, COMPANY)).toBe("titkos-jelszó");
  });

  // A másik cég sorába átmásolt titok nem fejthető vissza — a bérlő azonosítója
  // hitelesített része a rejtjelezésnek, nem megjegyzés a séma mellett.
  it("másik cég alatt nem fejthető vissza", () => {
    const enc = encryptSecret("titkos-jelszó", COMPANY);
    expect(() => decryptSecret(enc, OTHER)).toThrow();
  });

  it("ugyanaz a jelszó kétszer más rejtjelezett alakot ad", () => {
    expect(encryptSecret("azonos", COMPANY)).not.toBe(encryptSecret("azonos", COMPANY));
  });

  it("az ismeretlen formátumot elutasítja", () => {
    expect(() => decryptSecret("v9.a.b.c", COMPANY)).toThrow(/formátuma/);
  });
});
