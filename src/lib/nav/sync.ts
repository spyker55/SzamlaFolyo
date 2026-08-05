// Running a lekérdezés, and reading both sides of the comparison back.
//
// Server-only: it decrypts the technical user's credentials, so nothing here
// may ever be imported into a client component. node:crypto reaches it through
// secret.ts, which makes that a build error rather than a leak.

import type { SupabaseClient } from "@supabase/supabase-js";
import { decryptSecret } from "@/lib/nav/secret";
import { navSoftware, type NavEnvironment } from "@/lib/nav/config";
import type { NavCredentials } from "@/lib/nav/client";
import {
  invoiceNumberKey,
  queryDigestPage,
  queryInvoiceDigests,
  shiftDate,
  type NavDirection,
} from "@/lib/nav/query";
import { budapestToday } from "@/lib/nav/reconcile";
import type { NavSide, RegisterSide } from "@/lib/nav/reconcile";
import { taxNumberCore } from "@/lib/partner/identity";

export type CredentialRow = {
  id: string;
  company_id: string;
  tax_number: string;
  environment: NavEnvironment;
  login: string;
  password_enc: string;
  sign_key_enc: string;
  last_ok_at: string | null;
  last_error: string | null;
  updated_at: string;
};

export async function loadCredentialRow(
  supabase: SupabaseClient
): Promise<CredentialRow | null> {
  const { data } = await supabase
    .from("nav_credential")
    .select(
      "id, company_id, tax_number, environment, login, password_enc, sign_key_enc, last_ok_at, last_error, updated_at"
    )
    .limit(1)
    .maybeSingle();
  return (data ?? null) as CredentialRow | null;
}

export function credentialsOf(row: CredentialRow): NavCredentials {
  return {
    taxNumber: row.tax_number,
    login: row.login,
    password: decryptSecret(row.password_enc, row.company_id),
    signKey: decryptSecret(row.sign_key_enc, row.company_id),
    environment: row.environment,
  };
}

export type NavProbe = { from: string; to: string; count: number };

// The connection test asks the smallest version of the real question: one page
// of incoming digests for the last week. INBOUND on purpose — it is the
// direction that needs the wider "Számlák lekérdezése" permission, so a pass
// here means the whole feature can run, not just half of it.
export async function testCredentials(row: CredentialRow): Promise<NavProbe> {
  const software = navSoftware();
  if (!software.ok) throw new Error(software.error);

  const to = budapestToday();
  const from = shiftDate(to, -7);
  const page = await queryDigestPage({
    credentials: credentialsOf(row),
    software: software.software,
    direction: "bejovo",
    from,
    to,
    page: 1,
  });

  return { from, to, count: page.digests.length };
}

export type SyncOutcome = {
  syncId: string;
  count: number;
  newCount: number;
  pageCount: number;
};

// One run: create the sync row, walk NAV, store what came back, close the row.
// The sync row is written **before** the network call and closed after, so an
// interrupted run stays visible as 'fut' rather than vanishing — an empty
// result has to be distinguishable from a run that never finished.
export async function runSync(
  supabase: SupabaseClient,
  args: {
    row: CredentialRow;
    direction: NavDirection;
    from: string;
    to: string;
    userId: string;
  }
): Promise<SyncOutcome> {
  const software = navSoftware();
  if (!software.ok) throw new Error(software.error);

  const { data: syncRow, error: syncError } = await supabase
    .from("nav_sync")
    .insert({
      company_id: args.row.company_id,
      direction: args.direction,
      date_from: args.from,
      date_to: args.to,
      status: "fut",
      created_by: args.userId,
    })
    .select("id")
    .single();

  if (syncError || !syncRow) {
    throw new Error(syncError?.message ?? "A lekérdezés nem indult el.");
  }

  const syncId = syncRow.id as string;

  try {
    const { digests, pageCount } = await queryInvoiceDigests({
      credentials: credentialsOf(args.row),
      software: software.software,
      direction: args.direction,
      from: args.from,
      to: args.to,
    });

    // Which of these did we already know about? Asked before the write,
    // because after it every row looks equally familiar.
    const { data: knownRows } = await supabase
      .from("nav_invoice")
      .select("transaction_key")
      .eq("direction", args.direction)
      .gte("issue_date", args.from)
      .lte("issue_date", args.to)
      .limit(10_000);
    const known = new Set((knownRows ?? []).map((r) => r.transaction_key as string));
    const newCount = digests.filter((d) => !known.has(d.transactionKey)).length;

    const now = new Date().toISOString();
    const rows = digests.map((d) => ({
      company_id: args.row.company_id,
      direction: args.direction,
      transaction_key: d.transactionKey,
      invoice_number: d.invoiceNumber,
      invoice_number_key: d.invoiceNumberKey,
      invoice_operation: d.invoiceOperation,
      invoice_category: d.invoiceCategory,
      original_invoice_number: d.originalInvoiceNumber,
      partner_tax_number: d.partnerTaxNumber,
      partner_tax_core: d.partnerTaxCore,
      partner_group_tax_core: d.partnerGroupTaxCore,
      partner_name: d.partnerName,
      issue_date: d.issueDate,
      fulfillment_date: d.fulfillmentDate,
      payment_date: d.paymentDate,
      currency: d.currency,
      net_amount: d.netAmount,
      vat_amount: d.vatAmount,
      net_amount_huf: d.netAmountHuf,
      vat_amount_huf: d.vatAmountHuf,
      ins_date: d.insDate,
      completeness: d.completeness,
      nav_source: d.navSource,
      raw: d.raw,
      last_seen_at: now,
    }));

    for (let i = 0; i < rows.length; i += 500) {
      const { error } = await supabase
        .from("nav_invoice")
        .upsert(rows.slice(i, i + 500), { onConflict: "company_id,direction,transaction_key" });
      if (error) throw new Error(error.message);
    }

    await supabase
      .from("nav_sync")
      .update({
        status: "kesz",
        invoice_count: digests.length,
        new_count: newCount,
        page_count: pageCount,
        finished_at: new Date().toISOString(),
      })
      .eq("id", syncId);

    return { syncId, count: digests.length, newCount, pageCount };
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    await supabase
      .from("nav_sync")
      .update({ status: "hiba", error: message.slice(0, 500), finished_at: new Date().toISOString() })
      .eq("id", syncId);
    throw err;
  }
}

// Reading both sides back for the screen ------------------------------

type NavInvoiceDb = {
  id: string;
  invoice_number: string;
  invoice_number_key: string;
  partner_tax_core: string | null;
  partner_group_tax_core: string | null;
  partner_name: string | null;
  issue_date: string | null;
  currency: string | null;
  net_amount: string | number | null;
  vat_amount: string | number | null;
  invoice_operation: string | null;
  invoice_category: string | null;
  ins_date: string | null;
};

// PostgREST hands NUMERIC back as a string so that no precision is lost on the
// wire. Number() is applied once, here, rather than everywhere it is read.
function toNumber(value: string | number | null): number | null {
  if (value === null) return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

export async function loadNavSide(
  supabase: SupabaseClient,
  args: { direction: NavDirection; from: string; to: string }
): Promise<NavSide[]> {
  const { data } = await supabase
    .from("nav_invoice")
    .select(
      "id, invoice_number, invoice_number_key, partner_tax_core, partner_group_tax_core, partner_name, issue_date, currency, net_amount, vat_amount, invoice_operation, invoice_category, ins_date"
    )
    .eq("direction", args.direction)
    .gte("issue_date", args.from)
    .lte("issue_date", args.to)
    .order("issue_date", { ascending: false })
    .limit(5000);

  return ((data ?? []) as unknown as NavInvoiceDb[]).map((r) => ({
    id: r.id,
    invoiceNumber: r.invoice_number,
    invoiceNumberKey: r.invoice_number_key,
    partnerTaxCore: r.partner_tax_core,
    partnerGroupTaxCore: r.partner_group_tax_core,
    partnerName: r.partner_name,
    issueDate: r.issue_date,
    currency: r.currency,
    netAmount: toNumber(r.net_amount),
    vatAmount: toNumber(r.vat_amount),
    invoiceOperation: r.invoice_operation,
    invoiceCategory: r.invoice_category,
    insDate: r.ins_date,
  }));
}

type DocumentDb = {
  id: string;
  ugy_id: string | null;
  iktatoszam: string | null;
  irat_szama: string | null;
  doc_kind: string | null;
  issue_date: string | null;
  currency: string | null;
  gross_amount: string | number | null;
  processing_status: string;
  partner: { name: string | null; tax_number: string | null } | null;
};

// Every irat we hold, not only the filed ones. The question the screen asks is
// "megvan-e nálunk?", and an invoice waiting in the Beérkező is in the
// company's hands — reporting it as missing the day after it arrived would be
// wrong and would teach people to skim the list.
export async function loadRegisterSide(
  supabase: SupabaseClient,
  args: { direction: NavDirection; from: string; to: string }
): Promise<RegisterSide[]> {
  const { data } = await supabase
    .from("document")
    .select(
      "id, ugy_id, iktatoszam, irat_szama, doc_kind, issue_date, currency, gross_amount, processing_status, partner:partner_id (name, tax_number)"
    )
    .eq("direction", args.direction)
    .is("deleted_at", null)
    .gte("issue_date", args.from)
    .lte("issue_date", args.to)
    .limit(5000);

  return ((data ?? []) as unknown as DocumentDb[]).map((r) => ({
    id: r.id,
    ugyId: r.ugy_id,
    iktatoszam: r.iktatoszam,
    iratSzama: r.irat_szama,
    iratSzamaKey: invoiceNumberKey(r.irat_szama),
    partnerTaxCore: taxNumberCore(r.partner?.tax_number ?? null),
    partnerName: r.partner?.name ?? null,
    docKind: r.doc_kind,
    issueDate: r.issue_date,
    currency: r.currency,
    grossAmount: toNumber(r.gross_amount),
    ervenytelenitve: r.processing_status === "ervenytelenitve",
  }));
}
