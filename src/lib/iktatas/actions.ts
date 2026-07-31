"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";

export type IktatValues = Record<string, string | number | null>;

export type IktatResult =
  | { ok: true; iktatoszam: string; nextDocumentId: string | null }
  | { ok: false; error: string };

// Runs the whole iktatás as one Postgres transaction (iktat_document RPC),
// then finds the next document waiting for review so the reviewer can keep
// moving with Enter.
export async function iktat(
  documentId: string,
  values: IktatValues,
  // null opens a new ügy with a fresh főszám; an id files the irat under that
  // ügy as its next alszám.
  ugyId: string | null = null
): Promise<IktatResult> {
  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase.rpc("iktat_document", {
    p_document_id: documentId,
    p_values: values,
    p_ugy_id: ugyId,
  });

  if (error) {
    return { ok: false, error: hunError(error.message) };
  }

  const { data: next } = await supabase
    .from("document")
    .select("id")
    .in("processing_status", ["needs_review", "extraction_failed"])
    .is("deleted_at", null)
    .neq("id", documentId)
    .order("created_at", { ascending: true })
    .limit(1)
    .maybeSingle();

  return {
    ok: true,
    iktatoszam: (data as { iktatoszam: string }).iktatoszam,
    nextDocumentId: next?.id ?? null,
  };
}

export type ErvenytelenitResult = { ok: true } | { ok: false; error: string };

// The one thing that may happen to an iktatott irat. The iktatószám stays
// occupied and the ügy is left alone — see 20260730000015_ervenytelenites.sql
// for why. Irreversible by design, which is why the UI asks twice.
export async function ervenytelenit(
  documentId: string,
  indoklas: string
): Promise<ErvenytelenitResult> {
  const supabase = await createSupabaseServerClient();

  const { error } = await supabase.rpc("ervenytelenit_document", {
    p_document_id: documentId,
    p_indoklas: indoklas,
  });

  if (error) {
    return { ok: false, error: hunErvenytelenitError(error.message) };
  }

  revalidatePath("/iktatokonyv");
  return { ok: true };
}

function hunErvenytelenitError(message: string): string {
  if (message.includes("requires a reason")) {
    return "Az érvénytelenítéshez indoklás kell, legalább 5 karakter.";
  }
  if (message.includes("owner or admin")) {
    return "Érvényteleníteni csak tulajdonos vagy adminisztrátor tud.";
  }
  if (message.includes("already ervenytelenitve")) {
    return "Ez az irat már érvénytelenítve van.";
  }
  if (message.includes("only an iktatott irat")) {
    return "Csak iktatott iratot lehet érvényteleníteni.";
  }
  if (message.includes("document not found")) {
    return "Az irat nem található.";
  }
  return "Az érvénytelenítés nem sikerült: " + message;
}

function hunError(message: string): string {
  if (message.includes("direction and doc_kind")) {
    return "Az irány és az irat fajtája kötelező az iktatáshoz.";
  }
  if (message.includes("not reviewable")) {
    return "Ez az irat már nem iktatható (lehet, hogy közben más iktatta).";
  }
  if (message.includes("ugy not found")) {
    return "A választott ügy nem található.";
  }
  // Checked before the iktatókönyv case below: this message does not say
  // "closed", it names the ügy's own status.
  if (message.includes("no further irat can be filed")) {
    return "A választott ügy le van zárva, nem iktatható alá további irat.";
  }
  if (message.includes("closed")) {
    return "Az iktatókönyv le van zárva erre az évre.";
  }
  return "Az iktatás nem sikerült: " + message;
}
