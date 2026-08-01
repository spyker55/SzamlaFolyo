import { notFound } from "next/navigation";
import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { UgyDetail } from "@/components/ugy/UgyDetail";
import { isUgyStatus } from "@/lib/ugy/status";

type UgyRow = {
  id: string;
  foszam: number;
  ev: number;
  targy: string | null;
  status: string;
  hatarido: string | null;
  irattari_jel: string | null;
  eloado_user_id: string | null;
  opened_at: string;
  closed_at: string | null;
  irattarba_helyezve_at: string | null;
  partner: { name: string; tax_number: string | null } | null;
};

type DocRow = {
  id: string;
  alszam: number | null;
  iktatoszam: string | null;
  doc_kind: string | null;
  direction: string | null;
  targy: string | null;
  irat_szama: string | null;
  erkezett_at: string | null;
  due_date: string | null;
  gross_amount: string | number | null;
  currency: string | null;
  processing_status: string;
  fizetve_at: string | null;
  ervenytelenites_indoka: string | null;
};

function todayInBudapest(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Budapest" }).format(new Date());
}

export default async function UgyPage({ params }: { params: Promise<{ ugyId: string }> }) {
  await requireMembership();
  const { ugyId } = await params;
  const supabase = await createSupabaseServerClient();

  // All three are keyed by the id in the URL or by the signed-in company, so
  // none of them needs an earlier answer.
  const [{ data }, { data: docData }, { data: memberData }] = await Promise.all([
    supabase
      .from("ugy")
      .select(
        `id, foszam, ev, targy, status, hatarido, irattari_jel, eloado_user_id,
         opened_at, closed_at, irattarba_helyezve_at,
         partner:partner_id (name, tax_number)`
      )
      .eq("id", ugyId)
      .maybeSingle(),
    supabase
      .from("document")
      .select(
        `id, alszam, iktatoszam, doc_kind, direction, targy, irat_szama, erkezett_at,
         due_date, gross_amount, currency, processing_status, fizetve_at,
         ervenytelenites_indoka`
      )
      .eq("ugy_id", ugyId)
      .is("deleted_at", null)
      .order("alszam", { ascending: true })
      .limit(500),
    supabase
      .from("company_member")
      .select("user_id, app_user:user_id (full_name, email)")
      .limit(100),
  ]);

  // RLS makes another company's ugy invisible, so "not found" and "not yours"
  // are the same answer here, which is the answer we want to give.
  const ugy = data as unknown as UgyRow | null;
  if (!ugy || !isUgyStatus(ugy.status)) notFound();

  const members = ((memberData ?? []) as unknown as {
    user_id: string;
    app_user: { full_name: string | null; email: string } | null;
  }[]).map((m) => ({
    id: m.user_id,
    name: m.app_user?.full_name ?? m.app_user?.email ?? m.user_id,
  }));

  const iratok = ((docData ?? []) as unknown as DocRow[]).map((d) => ({
    id: d.id,
    alszam: d.alszam,
    iktatoszam: d.iktatoszam,
    docKind: d.doc_kind,
    direction: d.direction,
    targy: d.targy,
    iratSzama: d.irat_szama,
    erkezettAt: d.erkezett_at,
    dueDate: d.due_date,
    grossAmount: d.gross_amount === null ? null : Number(d.gross_amount),
    currency: d.currency,
    status: d.processing_status,
    fizetveAt: d.fizetve_at,
    ervenytelenitesIndoka: d.ervenytelenites_indoka,
  }));

  return (
    <UgyDetail
      ugy={{
        id: ugy.id,
        iktatoszam: `IKT/${ugy.foszam}/${ugy.ev}`,
        foszam: ugy.foszam,
        ev: ugy.ev,
        targy: ugy.targy ?? "",
        status: ugy.status,
        hatarido: ugy.hatarido,
        irattariJel: ugy.irattari_jel ?? "",
        eloadoUserId: ugy.eloado_user_id,
        openedAt: ugy.opened_at,
        closedAt: ugy.closed_at,
        irattarbaHelyezveAt: ugy.irattarba_helyezve_at,
        partnerName: ugy.partner?.name ?? null,
        partnerTaxNumber: ugy.partner?.tax_number ?? null,
      }}
      iratok={iratok}
      members={members}
      today={todayInBudapest()}
    />
  );
}
