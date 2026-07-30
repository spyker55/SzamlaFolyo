import { notFound } from "next/navigation";
import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { ReviewClient, type ReviewData } from "@/components/ellenorzes/ReviewClient";

export default async function EllenorzesPage({
  params,
}: {
  params: Promise<{ documentId: string }>;
}) {
  await requireMembership();
  const { documentId } = await params;
  const supabase = await createSupabaseServerClient();

  const { data: doc } = await supabase
    .from("document")
    .select(
      "id, processing_status, direction, doc_kind, targy, irat_szama, erkezett_at, issue_date, due_date, melleklet_db, kezelesi_feljegyzes, currency, net_amount, vat_amount, gross_amount, partner_id"
    )
    .eq("id", documentId)
    .is("deleted_at", null)
    .maybeSingle();

  if (!doc) notFound();

  if (doc.processing_status !== "needs_review" && doc.processing_status !== "extraction_failed") {
    return (
      <div className="p-8 text-center text-gray-500">
        Ez az irat már nem vár ellenőrzésre (állapot: {doc.processing_status}).
      </div>
    );
  }

  const { data: extraction } = await supabase
    .from("extraction")
    .select("parsed_fields, field_confidence")
    .eq("document_id", documentId)
    .is("error", null)
    .not("parsed_fields", "is", null)
    .order("finished_at", { ascending: false })
    .limit(1)
    .maybeSingle();

  const { data: file } = await supabase
    .from("document_file")
    .select("storage_path, mime_type, original_filename")
    .eq("document_id", documentId)
    .order("created_at", { ascending: true })
    .limit(1)
    .maybeSingle();

  let fileUrl: string | null = null;
  if (file) {
    const { data: signed } = await supabase.storage
      .from("iratok")
      .createSignedUrl(file.storage_path, 600);
    fileUrl = signed?.signedUrl ?? null;
  }

  const parsed = (extraction?.parsed_fields ?? {}) as Record<string, unknown>;
  const confidence =
    ((extraction?.field_confidence as { combined?: Record<string, number> } | null)?.combined) ??
    {};

  let partnerName: string | null = null;
  let partnerTax: string | null = null;
  if (doc.partner_id) {
    const { data: partner } = await supabase
      .from("partner")
      .select("name, tax_number")
      .eq("id", doc.partner_id)
      .maybeSingle();
    partnerName = partner?.name ?? null;
    partnerTax = partner?.tax_number ?? null;
  }

  const data: ReviewData = {
    documentId: doc.id,
    fileUrl,
    fileMimeType: file?.mime_type ?? null,
    fileName: file?.original_filename ?? null,
    confidence,
    tobbIratGyanu: Boolean(parsed.tobb_irat_gyanu),
    initial: {
      partner_name: partnerName ?? str(parsed.partner_name),
      partner_tax_number: partnerTax ?? str(parsed.partner_tax_number),
      targy: doc.targy ?? "",
      irat_szama: doc.irat_szama ?? "",
      erkezett_at: doc.erkezett_at ?? "",
      issue_date: doc.issue_date ?? "",
      due_date: doc.due_date ?? "",
      direction: doc.direction ?? "",
      doc_kind: doc.doc_kind ?? "",
      melleklet_db: doc.melleklet_db != null ? String(doc.melleklet_db) : "0",
      net_amount: num(doc.net_amount),
      vat_amount: num(doc.vat_amount),
      gross_amount: num(doc.gross_amount),
      currency: doc.currency?.trim() ?? "",
      kezelesi_feljegyzes: doc.kezelesi_feljegyzes ?? "",
      irattari_jel: "",
    },
  };

  return <ReviewClient data={data} />;
}

function str(v: unknown): string {
  return typeof v === "string" ? v : "";
}

function num(v: unknown): string {
  return v == null ? "" : String(v);
}
