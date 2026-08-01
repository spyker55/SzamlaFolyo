import Link from "next/link";
import { notFound } from "next/navigation";
import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { ReviewClient, type ReviewData } from "@/components/ellenorzes/ReviewClient";
import { formatAmountHu } from "@/lib/format/amount";
import { loadOpenUgyCandidates } from "@/lib/iktatas/ugy-candidates";
import { suggestUgy, ugyLabel } from "@/lib/iktatas/ugy-suggest";
import { EmptyState } from "@/components/ui/page";

export default async function EllenorzesPage({
  params,
}: {
  params: Promise<{ documentId: string }>;
}) {
  const { companyId } = await requireMembership();
  const { documentId } = await params;
  const supabase = await createSupabaseServerClient();

  // This is the screen the user waits on most, so nothing here queues behind
  // an answer it does not need. These four are all keyed by the id in the URL
  // or by the company, and used to run one after another.
  const [{ data: doc }, { data: extraction }, { data: file }, candidates] = await Promise.all([
    supabase
      .from("document")
      .select(
        "id, processing_status, direction, doc_kind, targy, irat_szama, erkezett_at, issue_date, due_date, melleklet_db, kezelesi_feljegyzes, currency, net_amount, vat_amount, gross_amount, partner_id"
      )
      .eq("id", documentId)
      .is("deleted_at", null)
      .maybeSingle(),
    supabase
      .from("extraction")
      .select("parsed_fields, field_confidence")
      .eq("document_id", documentId)
      .is("error", null)
      .not("parsed_fields", "is", null)
      .order("finished_at", { ascending: false })
      .limit(1)
      .maybeSingle(),
    supabase
      .from("document_file")
      .select("storage_path, mime_type, original_filename")
      .eq("document_id", documentId)
      .order("created_at", { ascending: true })
      .limit(1)
      .maybeSingle(),
    // Which open ügy might this irat belong to? Deterministic — same partner,
    // same amount — so the suggestion can state its own reason.
    loadOpenUgyCandidates(supabase, companyId),
  ]);

  if (!doc) notFound();

  if (doc.processing_status !== "needs_review" && doc.processing_status !== "extraction_failed") {
    return (
      <div className="card">
        <EmptyState
          hint={
            <>
              Valószínűleg közben valaki más iktatta.{" "}
              <Link href="/inbox" className="link">
                Vissza a Beérkezőhöz
              </Link>
            </>
          }
        >
          Ez az irat már nem vár ellenőrzésre.
        </EmptyState>
      </div>
    );
  }

  // These two do need answers from above — the storage path and the partner
  // id — but not from each other.
  const [signedUrl, partner] = await Promise.all([
    file
      ? supabase.storage.from("iratok").createSignedUrl(file.storage_path, 600)
      : null,
    doc.partner_id
      ? supabase.from("partner").select("name, tax_number").eq("id", doc.partner_id).maybeSingle()
      : null,
  ]);

  const fileUrl = signedUrl?.data?.signedUrl ?? null;
  const partnerName = partner?.data?.name ?? null;
  const partnerTax = partner?.data?.tax_number ?? null;

  const parsed = (extraction?.parsed_fields ?? {}) as Record<string, unknown>;
  const confidence =
    ((extraction?.field_confidence as { combined?: Record<string, number> } | null)?.combined) ??
    {};

  const ugySuggestions = suggestUgy(
    {
      partnerName: partnerName ?? str(parsed.partner_name),
      grossAmount: doc.gross_amount === null ? null : Number(doc.gross_amount),
      currency: doc.currency?.trim() ?? null,
      docKind: doc.doc_kind ?? null,
    },
    candidates
  );

  const data: ReviewData = {
    documentId: doc.id,
    fileUrl,
    fileMimeType: file?.mime_type ?? null,
    fileName: file?.original_filename ?? null,
    confidence,
    tobbIratGyanu: Boolean(parsed.tobb_irat_gyanu),
    ugySuggestions,
    ugyOptions: candidates.map((c) => ({ id: c.id, label: ugyLabel(c) })),
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
      // Formatted on the server so the markup and the hydrated client state
      // are the same string.
      net_amount: amount(doc.net_amount),
      vat_amount: amount(doc.vat_amount),
      gross_amount: amount(doc.gross_amount),
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

function amount(v: unknown): string {
  if (v == null) return "";
  return formatAmountHu(typeof v === "number" ? v : String(v));
}
