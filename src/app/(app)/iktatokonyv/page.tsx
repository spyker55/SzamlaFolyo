import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { Iktatokonyv } from "@/components/iktatokonyv/IktatokonyvTable";

type Row = {
  id: string;
  iktatoszam: string | null;
  irat_szama: string | null;
  targy: string | null;
  erkezett_at: string | null;
  melleklet_db: number;
  kezelesi_feljegyzes: string | null;
  processing_status: string;
  direction: string | null;
  doc_kind: string | null;
  partner: { name: string } | null;
  ugy: {
    hatarido: string | null;
    irattari_jel: string | null;
    irattarba_helyezve_at: string | null;
    eloado: { full_name: string | null; email: string } | null;
  } | null;
};

export default async function IktatokonyvPage() {
  await requireMembership();
  const supabase = await createSupabaseServerClient();

  const { data } = await supabase
    .from("document")
    .select(
      `id, iktatoszam, irat_szama, targy, erkezett_at, melleklet_db,
       kezelesi_feljegyzes, processing_status, direction, doc_kind,
       partner:partner_id (name),
       ugy:ugy_id (hatarido, irattari_jel, irattarba_helyezve_at,
         eloado:eloado_user_id (full_name, email))`
    )
    .in("processing_status", ["iktatva", "ervenytelenitve"])
    .order("iktatoszam", { ascending: false })
    .limit(500);

  const rows = ((data ?? []) as unknown as Row[]).map((d) => ({
    id: d.id,
    iktatoszam: d.iktatoszam ?? "",
    eloado: d.ugy?.eloado?.full_name ?? d.ugy?.eloado?.email ?? "",
    irattariJel: d.ugy?.irattari_jel ?? "",
    erkezett: d.erkezett_at ?? "",
    bekuldo: d.partner?.name ?? "",
    iratSzama: d.irat_szama ?? "",
    mellekletDb: d.melleklet_db,
    targy: d.targy ?? "",
    kezelesiFeljegyzes: d.kezelesi_feljegyzes ?? "",
    hatarido: d.ugy?.hatarido ?? "",
    irattarbaHelyezve: d.ugy?.irattarba_helyezve_at
      ? d.ugy.irattarba_helyezve_at.slice(0, 10)
      : "",
    ervenytelen: d.processing_status === "ervenytelenitve",
    direction: d.direction ?? "",
    docKind: d.doc_kind ?? "",
  }));

  return (
    <div>
      <h1 className="text-xl font-semibold">Iktatókönyv</h1>
      <Iktatokonyv rows={rows} />
    </div>
  );
}
