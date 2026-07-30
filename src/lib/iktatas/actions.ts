"use server";

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
  values: IktatValues
): Promise<IktatResult> {
  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase.rpc("iktat_document", {
    p_document_id: documentId,
    p_values: values,
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

function hunError(message: string): string {
  if (message.includes("direction and doc_kind")) {
    return "Az irány és az irat fajtája kötelező az iktatáshoz.";
  }
  if (message.includes("not reviewable")) {
    return "Ez az irat már nem iktatható (lehet, hogy közben más iktatta).";
  }
  if (message.includes("closed")) {
    return "Az iktatókönyv le van zárva erre az évre.";
  }
  return "Az iktatás nem sikerült: " + message;
}
