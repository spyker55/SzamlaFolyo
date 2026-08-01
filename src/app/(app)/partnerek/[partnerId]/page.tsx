import { notFound } from "next/navigation";
import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { PartnerDetail } from "@/components/partner/PartnerDetail";

type PartnerRow = {
  id: string;
  name: string;
  tax_number: string | null;
  eu_tax_number: string | null;
  bank_account: string | null;
  address: string | null;
  email: string | null;
  country: string | null;
  is_supplier: boolean;
  is_customer: boolean;
  default_payment_term_days: number | null;
  note: string | null;
  deleted_at: string | null;
  merged_into_partner_id: string | null;
};

type DocRow = {
  id: string;
  ugy_id: string | null;
  iktatoszam: string | null;
  doc_kind: string | null;
  direction: string | null;
  targy: string | null;
  erkezett_at: string | null;
  due_date: string | null;
  gross_amount: string | number | null;
  currency: string | null;
  processing_status: string;
  fizetve_at: string | null;
};

type UgyRow = {
  id: string;
  foszam: number;
  ev: number;
  targy: string | null;
  status: string;
  hatarido: string | null;
};

type MergeRow = {
  id: string;
  survivor_id: string;
  loser_id: string;
  document_ids: string[];
  ugy_ids: string[];
  created_at: string;
  undone_at: string | null;
};

export default async function PartnerPage({
  params,
}: {
  params: Promise<{ partnerId: string }>;
}) {
  const membership = await requireMembership();
  const { partnerId } = await params;
  const supabase = await createSupabaseServerClient();

  // Five queries that were running one after another, and every one of them is
  // keyed by the partnerId from the URL — none needed the previous answer. On
  // this screen that was five sequential round trips before anything rendered.
  const [{ data }, { data: docData }, { data: ugyData }, { data: mergeData }, { data: otherData }] =
    await Promise.all([
      supabase
        .from("partner")
        .select(
          `id, name, tax_number, eu_tax_number, bank_account, address, email, country,
           is_supplier, is_customer, default_payment_term_days, note, deleted_at,
           merged_into_partner_id`
        )
        .eq("id", partnerId)
        .maybeSingle(),
      supabase
        .from("document")
        .select(
          `id, ugy_id, iktatoszam, doc_kind, direction, targy, erkezett_at, due_date,
           gross_amount, currency, processing_status, fizetve_at`
        )
        .eq("partner_id", partnerId)
        .is("deleted_at", null)
        .order("erkezett_at", { ascending: false })
        .limit(500),
      supabase
        .from("ugy")
        .select("id, foszam, ev, targy, status, hatarido")
        .eq("partner_id", partnerId)
        .order("ev", { ascending: false })
        .order("foszam", { ascending: false })
        .limit(200),
      // Everything this partner was ever part of, on either side.
      supabase
        .from("partner_merge")
        .select("id, survivor_id, loser_id, document_ids, ugy_ids, created_at, undone_at")
        .or(`survivor_id.eq.${partnerId},loser_id.eq.${partnerId}`)
        .order("created_at", { ascending: false })
        .limit(50),
      // Every partner named anywhere in the merge history, plus the live rows
      // the merge picker can choose from.
      supabase
        .from("partner")
        .select("id, name, tax_number, deleted_at")
        .order("name")
        .limit(1000),
    ]);

  // RLS makes another company's partner invisible, so "not found" and "not
  // yours" are the same answer, which is the answer to give.
  const partner = data as unknown as PartnerRow | null;
  if (!partner) notFound();

  const merges = (mergeData ?? []) as unknown as MergeRow[];

  const allPartners = (otherData ?? []) as unknown as {
    id: string;
    name: string;
    tax_number: string | null;
    deleted_at: string | null;
  }[];

  const nameOf = new Map(allPartners.map((p) => [p.id, p.name]));

  return (
    <PartnerDetail
      partner={{
        id: partner.id,
        name: partner.name,
        taxNumber: partner.tax_number,
        euTaxNumber: partner.eu_tax_number,
        bankAccount: partner.bank_account,
        address: partner.address,
        email: partner.email,
        country: partner.country,
        isSupplier: partner.is_supplier,
        isCustomer: partner.is_customer,
        paymentTermDays: partner.default_payment_term_days,
        note: partner.note,
        retired: partner.deleted_at !== null,
        mergedIntoId: partner.merged_into_partner_id,
        mergedIntoName: partner.merged_into_partner_id
          ? nameOf.get(partner.merged_into_partner_id) ?? null
          : null,
      }}
      iratok={((docData ?? []) as unknown as DocRow[]).map((d) => ({
        id: d.id,
        ugyId: d.ugy_id,
        iktatoszam: d.iktatoszam,
        docKind: d.doc_kind,
        direction: d.direction,
        targy: d.targy,
        erkezettAt: d.erkezett_at,
        dueDate: d.due_date,
        grossAmount: d.gross_amount === null ? null : Number(d.gross_amount),
        currency: d.currency,
        status: d.processing_status,
        fizetveAt: d.fizetve_at,
      }))}
      ugyek={((ugyData ?? []) as unknown as UgyRow[]).map((u) => ({
        id: u.id,
        iktatoszam: `IKT/${u.foszam}/${u.ev}`,
        targy: u.targy ?? "",
        status: u.status,
        hatarido: u.hatarido,
      }))}
      merges={merges.map((m) => ({
        id: m.id,
        survivorId: m.survivor_id,
        loserId: m.loser_id,
        survivorName: nameOf.get(m.survivor_id) ?? "",
        loserName: nameOf.get(m.loser_id) ?? "",
        documentCount: m.document_ids.length,
        ugyCount: m.ugy_ids.length,
        createdAt: m.created_at,
        undoneAt: m.undone_at,
      }))}
      candidates={allPartners
        .filter((p) => p.deleted_at === null && p.id !== partnerId)
        .map((p) => ({ id: p.id, name: p.name, taxNumber: p.tax_number }))}
      canMerge={membership.role === "owner" || membership.role === "admin"}
    />
  );
}
