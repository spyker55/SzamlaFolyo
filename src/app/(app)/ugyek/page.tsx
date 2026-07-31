import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { UgyekClient, type UgyRow } from "@/components/ugy/UgyekClient";
import { compareUgyForList } from "@/lib/ugy/order";
import { isUgyStatus, type UgyStatus } from "@/lib/ugy/status";

type Row = {
  id: string;
  foszam: number;
  ev: number;
  targy: string | null;
  status: string;
  hatarido: string | null;
  irattari_jel: string | null;
  partner: { name: string } | null;
  eloado: { full_name: string | null; email: string } | null;
};

type DocRow = {
  ugy_id: string | null;
  gross_amount: string | number | null;
  currency: string | null;
  processing_status: string;
};

function todayInBudapest(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Budapest" }).format(new Date());
}

export default async function UgyekPage({
  searchParams,
}: {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
}) {
  await requireMembership();
  const params = await searchParams;
  const statusParam = typeof params.allapot === "string" ? params.allapot : "";
  const status: UgyStatus | "aktiv" | "mind" =
    isUgyStatus(statusParam) || statusParam === "mind" ? statusParam : "aktiv";

  const supabase = await createSupabaseServerClient();

  let query = supabase
    .from("ugy")
    .select(
      `id, foszam, ev, targy, status, hatarido, irattari_jel,
       partner:partner_id (name),
       eloado:eloado_user_id (full_name, email)`
    )
    .limit(500);

  // "Aktív" is the default because a closed ugy is not something you act on.
  if (status === "aktiv") query = query.in("status", ["folyamatban", "felfuggesztve"]);
  else if (status !== "mind") query = query.eq("status", status);

  const { data } = await query;
  const rows = (data ?? []) as unknown as Row[];

  // A second plain query rather than an embed: the counts are per ugy and
  // PostgREST aggregates on embedded rows have bitten this project before.
  const { data: docData } = await supabase
    .from("document")
    .select("ugy_id, gross_amount, currency, processing_status")
    .not("ugy_id", "is", null)
    .is("deleted_at", null)
    .limit(2000);

  const stats = new Map<string, { count: number; totals: Map<string, number> }>();
  for (const d of (docData ?? []) as unknown as DocRow[]) {
    if (!d.ugy_id) continue;
    const s = stats.get(d.ugy_id) ?? { count: 0, totals: new Map() };
    s.count += 1;
    // An érvénytelenített irat still belongs to the ugy and still shows in
    // the count, but it is not money owed, so it stays out of the total.
    if (d.processing_status === "iktatva" && d.gross_amount !== null) {
      const currency = d.currency ?? "—";
      s.totals.set(currency, (s.totals.get(currency) ?? 0) + Number(d.gross_amount));
    }
    stats.set(d.ugy_id, s);
  }

  const ugyek: UgyRow[] = rows
    .sort(compareUgyForList)
    .map((u) => {
      const s = stats.get(u.id);
      return {
        id: u.id,
        iktatoszam: `IKT/${u.foszam}/${u.ev}`,
        foszam: u.foszam,
        ev: u.ev,
        targy: u.targy ?? "",
        status: u.status,
        hatarido: u.hatarido,
        irattariJel: u.irattari_jel ?? "",
        partnerName: u.partner?.name ?? "",
        eloado: u.eloado?.full_name ?? u.eloado?.email ?? "",
        iratCount: s?.count ?? 0,
        totals: [...(s?.totals ?? new Map())]
          .map(([currency, amount]) => ({ currency, amount: Number(amount.toFixed(4)) }))
          .sort((a, b) => a.currency.localeCompare(b.currency, "hu")),
      };
    });

  return (
    <div>
      <h1 className="text-xl font-semibold">Ügyek</h1>
      <UgyekClient ugyek={ugyek} status={status} today={todayInBudapest()} />
    </div>
  );
}
