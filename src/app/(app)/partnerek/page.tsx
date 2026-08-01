import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { PartnerekClient, type PartnerRow } from "@/components/partner/PartnerekClient";
import { findDuplicateCandidates } from "@/lib/partner/duplicates";
import {
  PAYABLE_KINDS,
  sumByCurrency,
  withoutSupersededDijbekero,
  type PayableDocument,
} from "@/lib/fizetes/schedule";
import { PageHeader } from "@/components/ui/page";

type Row = {
  id: string;
  name: string;
  tax_number: string | null;
  bank_account: string | null;
  is_supplier: boolean;
  is_customer: boolean;
  default_payment_term_days: number | null;
};

type DocRow = {
  id: string;
  partner_id: string | null;
  ugy_id: string | null;
  doc_kind: string | null;
  direction: string | null;
  processing_status: string;
  erkezett_at: string | null;
  due_date: string | null;
  gross_amount: string | number | null;
  currency: string | null;
  fizetve_at: string | null;
};

export default async function PartnerekPage() {
  await requireMembership();
  const supabase = await createSupabaseServerClient();

  // Neither query needs the other's answer, so they go out together.
  const [{ data: partnerData }, { data: docData }] = await Promise.all([
    supabase
      .from("partner")
      .select(
        "id, name, tax_number, bank_account, is_supplier, is_customer, default_payment_term_days"
      )
      // Retired partners are reached from the row they were merged into, not
      // from the list: showing them here would put the duplicate back on screen.
      .is("deleted_at", null)
      .order("name")
      .limit(1000),
    supabase
      .from("document")
      .select(
        `id, partner_id, ugy_id, doc_kind, direction, processing_status,
         erkezett_at, due_date, gross_amount, currency, fizetve_at`
      )
      .not("partner_id", "is", null)
      .is("deleted_at", null)
      .limit(3000),
  ]);

  const partners = (partnerData ?? []) as unknown as Row[];
  const docs = (docData ?? []) as unknown as DocRow[];

  // Suppression is ügy-scoped, so it has to run over the whole register
  // before anything is grouped by partner — a díjbekérő and the invoice
  // answering it are one debt no matter whose row they land on.
  const payables: (PayableDocument & { partnerId: string })[] = docs
    .filter(
      (d) =>
        d.partner_id !== null &&
        d.processing_status === "iktatva" &&
        d.direction === "bejovo" &&
        PAYABLE_KINDS.includes(d.doc_kind ?? "")
    )
    .map((d) => ({
      id: d.id,
      iktatoszam: null,
      ugyId: d.ugy_id,
      docKind: d.doc_kind,
      partnerName: null,
      targy: null,
      dueDate: d.due_date,
      grossAmount: d.gross_amount === null ? null : Number(d.gross_amount),
      currency: d.currency,
      fizetveAt: d.fizetve_at,
      partnerId: d.partner_id as string,
    }));

  const outstanding = withoutSupersededDijbekero(payables).filter(
    (d) => !d.fizetveAt
  ) as (PayableDocument & { partnerId: string })[];

  const openByPartner = new Map<string, (PayableDocument & { partnerId: string })[]>();
  for (const d of outstanding) {
    const list = openByPartner.get(d.partnerId) ?? [];
    list.push(d);
    openByPartner.set(d.partnerId, list);
  }

  const stats = new Map<string, { count: number; last: string | null }>();
  for (const d of docs) {
    if (!d.partner_id) continue;
    const s = stats.get(d.partner_id) ?? { count: 0, last: null };
    s.count += 1;
    const date = d.erkezett_at;
    if (date && (s.last === null || date > s.last)) s.last = date;
    stats.set(d.partner_id, s);
  }

  const rows: PartnerRow[] = partners.map((p) => {
    const s = stats.get(p.id);
    return {
      id: p.id,
      name: p.name,
      taxNumber: p.tax_number,
      bankAccount: p.bank_account,
      isSupplier: p.is_supplier,
      isCustomer: p.is_customer,
      paymentTermDays: p.default_payment_term_days,
      iratCount: s?.count ?? 0,
      lastIratAt: s?.last ?? null,
      open: sumByCurrency(openByPartner.get(p.id) ?? []),
    };
  });

  const duplicates = findDuplicateCandidates(
    partners.map((p) => ({ id: p.id, name: p.name, taxNumber: p.tax_number }))
  );

  const nameOf = new Map(partners.map((p) => [p.id, p.name]));

  return (
    <div>
      <PageHeader
        title="Partnerek"
        description="A szállítók és vevők törzsadatai, a hozzájuk tartozó iratokkal és nyitott tartozással."
      />
      <PartnerekClient
        partners={rows}
        duplicates={duplicates.map((d) => ({
          ...d,
          aName: nameOf.get(d.aId) ?? "",
          bName: nameOf.get(d.bId) ?? "",
        }))}
      />
    </div>
  );
}
