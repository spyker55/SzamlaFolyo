// queryInvoiceDigest: the list of invoices NAV holds for a taxpayer.
//
// Two limits shape everything here, and both are NAV's:
//
//   - an invoiceIssueDate range may span at most 35 days, so any longer period
//     is cut into windows before it is asked for;
//   - a page holds at most 100 digests, and the response says how many pages
//     exist, so each window is walked to its last page.
//
// The digest is a summary, not the invoice: it has the number, the parties,
// the dates and the amounts, and that is all we need to answer "is this one in
// the register?". The full invoice XML would need queryInvoiceData per invoice
// — one request each — and would tell us nothing more about whether the irat
// arrived.

import { els, num, bool, txt, tag, type XmlNode } from "@/lib/nav/xml";
import { navRequest, type NavCredentials, type NavTransport } from "@/lib/nav/client";
import type { NavSoftware } from "@/lib/nav/config";
import { taxNumberCore } from "@/lib/partner/identity";

export type NavDirection = "bejovo" | "kimeno";

const NAV_DIRECTION: Record<NavDirection, string> = {
  bejovo: "INBOUND",
  kimeno: "OUTBOUND",
};

export type NavDigest = {
  transactionKey: string;
  invoiceNumber: string;
  invoiceNumberKey: string;
  invoiceOperation: string | null;
  invoiceCategory: string | null;
  originalInvoiceNumber: string | null;
  partnerTaxNumber: string | null;
  partnerTaxCore: string | null;
  partnerGroupTaxCore: string | null;
  partnerName: string | null;
  issueDate: string | null;
  fulfillmentDate: string | null;
  paymentDate: string | null;
  currency: string | null;
  netAmount: number | null;
  vatAmount: number | null;
  netAmountHuf: number | null;
  vatAmountHuf: number | null;
  insDate: string | null;
  completeness: boolean | null;
  navSource: string | null;
  raw: Record<string, string>;
};

// Case and whitespace fold; punctuation does not. A slash or a dash inside an
// invoice number is part of the number, and folding it away could merge two
// genuinely different invoices from the same supplier into one.
export function invoiceNumberKey(raw: string | null | undefined): string {
  if (!raw) return "";
  return raw.replace(/\s+/g, "").toUpperCase();
}

function flatten(node: XmlNode): Record<string, string> {
  const out: Record<string, string> = {};
  for (const child of node.children) {
    if (child.children.length === 0) {
      const value = child.text.trim();
      if (value !== "") out[child.name] = value;
    }
  }
  return out;
}

export function readDigest(node: XmlNode, direction: NavDirection): NavDigest {
  const invoiceNumber = txt(node, "invoiceNumber") ?? "";
  const partnerTaxNumber =
    direction === "bejovo" ? txt(node, "supplierTaxNumber") : txt(node, "customerTaxNumber");
  const groupTaxNumber =
    direction === "bejovo"
      ? txt(node, "supplierGroupMemberTaxNumber")
      : txt(node, "customerGroupMemberTaxNumber");

  const transactionId = txt(node, "transactionId");
  const index = txt(node, "index");
  const insDate = txt(node, "insDate");

  return {
    // transactionId + index is the identity of a reported invoice at NAV, and
    // it is what makes a re-sync idempotent. Where it is missing — old records
    // predating the field — the number and the submission time stand in, which
    // is unique for the same reason the transaction is.
    transactionKey:
      transactionId !== null
        ? `${transactionId}:${index ?? "1"}`
        : `noid:${invoiceNumberKey(invoiceNumber)}:${insDate ?? ""}`,
    invoiceNumber,
    invoiceNumberKey: invoiceNumberKey(invoiceNumber),
    invoiceOperation: txt(node, "invoiceOperation"),
    invoiceCategory: txt(node, "invoiceCategory"),
    originalInvoiceNumber: txt(node, "originalInvoiceNumber"),
    partnerTaxNumber,
    partnerTaxCore: taxNumberCore(partnerTaxNumber),
    partnerGroupTaxCore: taxNumberCore(groupTaxNumber),
    partnerName:
      direction === "bejovo" ? txt(node, "supplierName") : txt(node, "customerName"),
    issueDate: txt(node, "invoiceIssueDate"),
    fulfillmentDate: txt(node, "invoiceDeliveryDate"),
    paymentDate: txt(node, "paymentDate"),
    currency: txt(node, "currency"),
    netAmount: num(node, "invoiceNetAmount"),
    vatAmount: num(node, "invoiceVatAmount"),
    netAmountHuf: num(node, "invoiceNetAmountHUF"),
    vatAmountHuf: num(node, "invoiceVatAmountHUF"),
    insDate,
    completeness: bool(node, "completenessIndicator"),
    navSource: txt(node, "source"),
    raw: flatten(node),
  };
}

export type DigestPage = {
  currentPage: number;
  availablePage: number;
  digests: NavDigest[];
};

export function readDigestPage(root: XmlNode, direction: NavDirection): DigestPage {
  const result = root.children.find((c) => c.name === "invoiceDigestResult") ?? root;
  return {
    currentPage: num(result, "currentPage") ?? 1,
    // No result element at all means an empty answer, not a broken one: NAV
    // omits invoiceDigestResult when the range holds nothing.
    availablePage: num(result, "availablePage") ?? 0,
    digests: els(result, "invoiceDigest").map((node) => readDigest(node, direction)),
  };
}

// Inclusive on both ends, in Budapest-local calendar days — these are dates
// printed on invoices, not instants, so they are compared as strings and
// stepped as UTC midnights to stay clear of daylight saving.
export function splitDateRange(
  from: string,
  to: string,
  maxDays = 35
): Array<{ from: string; to: string }> {
  if (from > to) return [];

  const out: Array<{ from: string; to: string }> = [];
  const end = Date.parse(`${to}T00:00:00Z`);
  let cursor = Date.parse(`${from}T00:00:00Z`);
  const step = (maxDays - 1) * 86_400_000;

  while (cursor <= end) {
    const windowEnd = Math.min(cursor + step, end);
    out.push({
      from: new Date(cursor).toISOString().slice(0, 10),
      to: new Date(windowEnd).toISOString().slice(0, 10),
    });
    cursor = windowEnd + 86_400_000;
  }
  return out;
}

/** Calendar-day arithmetic on the same YYYY-MM-DD strings NAV speaks. */
export function shiftDate(date: string, days: number): string {
  return new Date(Date.parse(`${date}T00:00:00Z`) + days * 86_400_000).toISOString().slice(0, 10);
}

export function digestRequestBody(args: {
  page: number;
  direction: NavDirection;
  from: string;
  to: string;
}): string {
  return (
    tag("page", args.page) +
    tag("invoiceDirection", NAV_DIRECTION[args.direction]) +
    "<invoiceQueryParams><mandatoryQueryParams><invoiceIssueDate>" +
    tag("dateFrom", args.from) +
    tag("dateTo", args.to) +
    "</invoiceIssueDate></mandatoryQueryParams></invoiceQueryParams>"
  );
}

/** One request, one page. The paging walk and the connection test share it. */
export async function queryDigestPage(args: {
  credentials: NavCredentials;
  software: NavSoftware;
  direction: NavDirection;
  from: string;
  to: string;
  page: number;
  transport?: NavTransport;
}): Promise<DigestPage> {
  const root = await navRequest({
    operation: "QueryInvoiceDigest",
    credentials: args.credentials,
    software: args.software,
    body: digestRequestBody({
      page: args.page,
      direction: args.direction,
      from: args.from,
      to: args.to,
    }),
    transport: args.transport,
  });
  return readDigestPage(root, args.direction);
}

export type DigestQueryResult = {
  digests: NavDigest[];
  pageCount: number;
};

// Walks every window and every page of a range. Sequential on purpose: NAV
// rate-limits query operations per taxpayer, and a burst that gets throttled
// halfway through would leave the caller unable to tell "no invoice" from
// "not asked".
export async function queryInvoiceDigests(args: {
  credentials: NavCredentials;
  software: NavSoftware;
  direction: NavDirection;
  from: string;
  to: string;
  transport?: NavTransport;
  maxPages?: number;
}): Promise<DigestQueryResult> {
  const digests: NavDigest[] = [];
  const seen = new Set<string>();
  let pageCount = 0;
  const maxPages = args.maxPages ?? 200;

  for (const window of splitDateRange(args.from, args.to)) {
    let page = 1;
    let available = 1;

    while (page <= available) {
      if (pageCount >= maxPages) {
        throw new Error(
          `A lekérdezés ${maxPages} lapnál is többet adna vissza. Szűkíts az időszakon.`
        );
      }

      const parsed = await queryDigestPage({
        credentials: args.credentials,
        software: args.software,
        direction: args.direction,
        page,
        ...window,
        transport: args.transport,
      });
      pageCount += 1;
      available = parsed.availablePage;

      for (const digest of parsed.digests) {
        // Windows are disjoint, but a re-reported invoice can surface twice
        // inside one; the transaction key is the authority on sameness.
        if (seen.has(digest.transactionKey)) continue;
        seen.add(digest.transactionKey);
        digests.push(digest);
      }

      if (parsed.digests.length === 0) break;
      page += 1;
    }
  }

  return { digests, pageCount };
}
