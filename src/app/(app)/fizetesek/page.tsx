import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { FizetesekClient } from "@/components/fizetes/FizetesekClient";
import { buildSchedule, PAYABLE_KINDS, type PayableDocument } from "@/lib/fizetes/schedule";

type Row = {
  id: string;
  iktatoszam: string | null;
  ugy_id: string | null;
  doc_kind: string | null;
  targy: string | null;
  due_date: string | null;
  gross_amount: string | number | null;
  currency: string | null;
  fizetve_at: string | null;
  partner: { name: string } | null;
};

// The company's own day, not the server's: erkezett_at defaults to Budapest
// time too, so a deadline must not fall into a different bucket depending on
// where the request happened to be served.
function todayInBudapest(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Budapest" }).format(new Date());
}

export default async function FizetesekPage() {
  await requireMembership();
  const supabase = await createSupabaseServerClient();

  const { data } = await supabase
    .from("document")
    .select(
      `id, iktatoszam, ugy_id, doc_kind, targy, due_date, gross_amount, currency,
       fizetve_at, partner:partner_id (name)`
    )
    // 'iktatva' excludes érvénytelenített iratok: a withdrawn invoice is not
    // owed. Only incoming documents are debts — outgoing ones are receivables,
    // which is a different question.
    .eq("processing_status", "iktatva")
    .eq("direction", "bejovo")
    .in("doc_kind", [...PAYABLE_KINDS])
    .is("deleted_at", null)
    .limit(1000);

  const documents: PayableDocument[] = ((data ?? []) as unknown as Row[]).map((d) => ({
    id: d.id,
    iktatoszam: d.iktatoszam,
    ugyId: d.ugy_id,
    docKind: d.doc_kind,
    partnerName: d.partner?.name ?? null,
    targy: d.targy,
    dueDate: d.due_date,
    // PostgREST returns NUMERIC as a string.
    grossAmount: d.gross_amount === null ? null : Number(d.gross_amount),
    currency: d.currency,
    fizetveAt: d.fizetve_at,
  }));

  const today = todayInBudapest();
  const schedule = buildSchedule(documents, today);

  // A total the user might act on must say what it does not include.
  const { count: ellenorzesreVar } = await supabase
    .from("document")
    .select("id", { count: "exact", head: true })
    .in("processing_status", ["needs_review", "extraction_failed"])
    .is("deleted_at", null);

  return (
    <div>
      <h1 className="text-xl font-semibold">Fizetési naptár</h1>
      <FizetesekClient
        schedule={schedule}
        today={today}
        ellenorzesreVar={ellenorzesreVar ?? 0}
      />
    </div>
  );
}
