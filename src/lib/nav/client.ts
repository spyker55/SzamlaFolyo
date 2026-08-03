// Talking to the Online Számla API.
//
// Only the read operations are here, and that is the whole design: this file
// has no way to submit an invoice. Számlafolyó registers invoices somebody
// else issued and somebody else already reported; a second report from here
// would be a duplicate data submission under the company's tax number. The
// absence is deliberate — see the migration header.

import {
  NAV_ENDPOINT,
  NAV_HEADER_VERSION,
  NAV_REQUEST_VERSION,
  type NavEnvironment,
  type NavSoftware,
} from "@/lib/nav/config";
import {
  headerTimestamp,
  newRequestId,
  passwordHash,
  requestSignature,
} from "@/lib/nav/signature";
import { el, els, escapeXml, parseXml, tag, txt, type XmlNode } from "@/lib/nav/xml";

export type NavCredentials = {
  /** The 8-digit törzsszám, which is what NAV's <taxNumber> takes. */
  taxNumber: string;
  login: string;
  password: string;
  signKey: string;
  environment: NavEnvironment;
};

export class NavError extends Error {
  readonly code: string | null;
  readonly details: string[];

  constructor(message: string, code: string | null = null, details: string[] = []) {
    super(message);
    this.name = "NavError";
    this.code = code;
    this.details = details;
  }
}

/** Injectable so the request builder and the parsers can be tested without a network. */
export type NavTransport = (url: string, body: string) => Promise<{ status: number; text: string }>;

const OSA_NS = "http://schemas.nav.gov.hu/OSA/3.0/api";
const COMMON_NS = "http://schemas.nav.gov.hu/NTCA/1.0/common";

function softwareXml(s: NavSoftware): string {
  return (
    "<software>" +
    tag("softwareId", s.softwareId) +
    tag("softwareName", s.softwareName) +
    tag("softwareOperation", s.softwareOperation) +
    tag("softwareMainVersion", s.softwareMainVersion) +
    tag("softwareDevName", s.softwareDevName) +
    tag("softwareDevContact", s.softwareDevContact) +
    tag("softwareDevCountryCode", s.softwareDevCountryCode) +
    tag("softwareDevTaxNumber", s.softwareDevTaxNumber) +
    "</software>"
  );
}

// The element order matters: the XSD declares a sequence, and NAV answers
// SCHEMA_VIOLATION for a request whose fields are all correct but in the wrong
// order. header, user, software, then the operation's own body.
export function buildRequest(args: {
  operation: string;
  credentials: NavCredentials;
  software: NavSoftware;
  body: string;
  at?: Date;
  requestId?: string;
}): { xml: string; requestId: string; at: Date } {
  const at = args.at ?? new Date();
  const requestId = args.requestId ?? newRequestId(at);
  const c = args.credentials;

  const xml =
    '<?xml version="1.0" encoding="UTF-8"?>' +
    `<${args.operation}Request xmlns="${OSA_NS}" xmlns:common="${COMMON_NS}">` +
    "<common:header>" +
    tag("common:requestId", requestId) +
    tag("common:timestamp", headerTimestamp(at)) +
    tag("common:requestVersion", NAV_REQUEST_VERSION) +
    tag("common:headerVersion", NAV_HEADER_VERSION) +
    "</common:header>" +
    "<common:user>" +
    tag("common:login", c.login) +
    `<common:passwordHash cryptoType="SHA-512">${escapeXml(passwordHash(c.password))}</common:passwordHash>` +
    tag("common:taxNumber", c.taxNumber) +
    `<common:requestSignature cryptoType="SHA3-512">${escapeXml(
      requestSignature(requestId, at, c.signKey)
    )}</common:requestSignature>` +
    "</common:user>" +
    softwareXml(args.software) +
    args.body +
    `</${args.operation}Request>`;

  return { xml, requestId, at };
}

const defaultTransport: NavTransport = async (url, body) => {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 60_000);
  try {
    const res = await fetch(url, {
      method: "POST",
      headers: {
        "content-type": "application/xml;charset=UTF-8",
        accept: "application/xml",
      },
      body,
      signal: controller.signal,
    });
    return { status: res.status, text: await res.text() };
  } finally {
    clearTimeout(timer);
  }
};

// NAV answers an error with a well-formed XML body and a 4xx status, so the
// status alone is never the whole story: the body is parsed either way, and
// the code inside it is what the user is told about.
function readResult(root: XmlNode): { funcCode: string | null; code: string | null; message: string | null } {
  const result = el(root, "result");
  return {
    funcCode: txt(result, "funcCode"),
    code: txt(result, "errorCode"),
    message: txt(result, "message"),
  };
}

function readValidationMessages(root: XmlNode): string[] {
  const out: string[] = [];
  for (const group of ["technicalValidationMessages", "technicalValidationMessage"]) {
    for (const node of els(root, group)) {
      const message = txt(node, "message") ?? txt(node, "validationErrorCode");
      if (message) out.push(message);
    }
  }
  return out;
}

export async function navRequest(args: {
  operation: string;
  credentials: NavCredentials;
  software: NavSoftware;
  body: string;
  transport?: NavTransport;
  at?: Date;
  requestId?: string;
}): Promise<XmlNode> {
  const { xml } = buildRequest(args);
  const url = `${NAV_ENDPOINT[args.credentials.environment]}/${args.operation.charAt(0).toLowerCase()}${args.operation.slice(1)}`;

  let response: { status: number; text: string };
  try {
    response = await (args.transport ?? defaultTransport)(url, xml);
  } catch (err) {
    const reason = err instanceof Error ? err.message : String(err);
    throw new NavError(`Nem sikerült elérni a NAV rendszerét (${reason}).`, "NETWORK");
  }

  let root: XmlNode;
  try {
    root = parseXml(response.text);
  } catch (err) {
    const reason = err instanceof Error ? err.message : String(err);
    throw new NavError(
      `A NAV válasza nem értelmezhető (HTTP ${response.status}: ${reason}).`,
      "UNPARSEABLE"
    );
  }

  const result = readResult(root);
  if (result.funcCode !== "OK") {
    throw new NavError(
      result.message ?? `A NAV elutasította a kérést (HTTP ${response.status}).`,
      result.code,
      readValidationMessages(root)
    );
  }

  return root;
}

// The lightest call that proves all four credentials are right, and it returns
// something a human can check: the taxpayer's own name as NAV holds it. "OK"
// on its own would not tell anyone whether they typed the right tax number.
export type TaxpayerCheck = {
  valid: boolean;
  name: string | null;
  shortName: string | null;
  vatCode: string | null;
  countyCode: string | null;
};

export async function queryTaxpayer(
  credentials: NavCredentials,
  software: NavSoftware,
  taxNumber: string,
  transport?: NavTransport
): Promise<TaxpayerCheck> {
  const root = await navRequest({
    operation: "QueryTaxpayer",
    credentials,
    software,
    body: tag("taxNumber", taxNumber),
    transport,
  });

  const data = el(root, "taxpayerData");
  const detail = el(data, "taxNumberDetail");
  return {
    // NAV omits taxpayerValidity entirely for an unknown tax number.
    valid: txt(root, "taxpayerValidity") === "true",
    name: txt(data, "taxpayerName"),
    shortName: txt(data, "taxpayerShortName"),
    vatCode: txt(detail, "vatCode"),
    countyCode: txt(detail, "countyCode"),
  };
}

export function navErrorMessage(err: unknown): string {
  if (!(err instanceof NavError)) {
    return err instanceof Error ? err.message : String(err);
  }

  const extra = err.details.length > 0 ? ` (${err.details.join("; ")})` : "";

  switch (err.code) {
    case "NETWORK":
      return `${err.message} A lekérdezés nem futott le, nyugodtan próbáld újra.`;
    case "INVALID_SECURITY_USER":
    case "INVALID_USER_RELATION":
      return (
        "A NAV nem fogadta el a technikai felhasználót. Ellenőrizd az adószámot, a felhasználónevet, " +
        "a jelszót és az aláíró kulcsot az Online Számla portálon — és azt, hogy a technikai " +
        "felhasználónak van-e „Számlák lekérdezése” jogosultsága."
      );
    case "INVALID_REQUEST_SIGNATURE":
      return (
        "A NAV elutasította a kérés aláírását. Ez majdnem mindig az aláíró kulcs (nem a jelszó): " +
        "másold be újra az Online Számla portálról."
      );
    case "NOT_REGISTERED_CUSTOMER":
      return "Ez az adószám nincs regisztrálva az Online Számla rendszerben.";
    case "SCHEMA_VIOLATION":
      return `A NAV a kérés formátumát utasította el: ${err.message}${extra}`;
    default:
      return `${err.message}${extra}${err.code ? ` [${err.code}]` : ""}`;
  }
}
