import type { createSupabaseServerClient } from "@/lib/supabase/server";
import type { UgyCandidate } from "./ugy-suggest";

type Db = Awaited<ReturnType<typeof createSupabaseServerClient>>;

const MAX_CANDIDATES = 100;

// The open ügyek an irat could be filed under, carrying everything the
// deterministic matcher needs. Plain queries rather than PostgREST embeds:
// embeds have already cost this project one production 400, and none of these
// joins is expensive at this size.
export async function loadOpenUgyCandidates(
  supabase: Db,
  companyId: string
): Promise<UgyCandidate[]> {
  const { data: ugyek } = await supabase
    .from("ugy")
    .select("id, foszam, ev, targy, partner_id, iktatokonyv_id")
    .eq("company_id", companyId)
    .eq("status", "folyamatban")
    .order("ev", { ascending: false })
    .order("foszam", { ascending: false })
    .limit(MAX_CANDIDATES);

  if (!ugyek || ugyek.length === 0) return [];

  const { data: konyvek } = await supabase
    .from("iktatokonyv")
    .select("id, prefix")
    .in("id", [...new Set(ugyek.map((u) => u.iktatokonyv_id as string))]);
  const prefixById = new Map((konyvek ?? []).map((k) => [k.id as string, k.prefix as string]));

  const { data: docs } = await supabase
    .from("document")
    .select("ugy_id, doc_kind, gross_amount, currency, partner_id")
    .in(
      "ugy_id",
      ugyek.map((u) => u.id as string)
    )
    .is("deleted_at", null);

  const partnerIds = [
    ...new Set(
      [...ugyek.map((u) => u.partner_id), ...(docs ?? []).map((d) => d.partner_id)].filter(
        (id): id is string => Boolean(id)
      )
    ),
  ];
  const { data: partners } =
    partnerIds.length > 0
      ? await supabase.from("partner").select("id, name").in("id", partnerIds)
      : { data: [] };
  const nameById = new Map((partners ?? []).map((p) => [p.id as string, p.name as string]));

  const byUgy = new Map<string, UgyCandidate>();
  for (const u of ugyek) {
    const ownPartner = u.partner_id ? nameById.get(u.partner_id as string) : undefined;
    byUgy.set(u.id as string, {
      id: u.id as string,
      prefix: prefixById.get(u.iktatokonyv_id as string) ?? "IKT",
      foszam: u.foszam as number,
      ev: u.ev as number,
      targy: (u.targy as string | null) ?? null,
      partnerNames: ownPartner ? [ownPartner] : [],
      documents: [],
    });
  }

  for (const d of docs ?? []) {
    const candidate = byUgy.get(d.ugy_id as string);
    if (!candidate) continue;
    candidate.documents.push({
      docKind: (d.doc_kind as string | null) ?? null,
      // PostgREST hands NUMERIC back as a string; comparing those as text
      // would make 13598 and 13598.0000 different amounts.
      grossAmount: d.gross_amount === null ? null : Number(d.gross_amount),
      currency: (d.currency as string | null) ?? null,
    });

    const name = d.partner_id ? nameById.get(d.partner_id as string) : undefined;
    if (name && !candidate.partnerNames.includes(name)) {
      candidate.partnerNames.push(name);
    }
  }

  // Preserve the query order — newest ügy first — because the matcher uses it
  // to break ties between equally strong candidates.
  return ugyek
    .map((u) => byUgy.get(u.id as string))
    .filter((c): c is UgyCandidate => c !== undefined);
}
