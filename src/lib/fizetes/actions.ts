"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";

export type FizetesResult = { ok: true } | { ok: false; error: string };

// Marking an irat paid is a later fact about it, not a change to what was
// filed — which is why app.protect_iktatott_document() does not guard these
// columns, and why this is a plain update rather than an RPC.
export async function jeloldKifizetve(
  documentId: string,
  fizetveAt: string | null
): Promise<FizetesResult> {
  const supabase = await createSupabaseServerClient();

  if (fizetveAt !== null && !/^\d{4}-\d{2}-\d{2}$/.test(fizetveAt)) {
    return { ok: false, error: "Érvénytelen dátum." };
  }

  const { data, error } = await supabase
    .from("document")
    .update({ fizetve_at: fizetveAt })
    .eq("id", documentId)
    // Only a filed irat can be settled: anything else is not yet a debt.
    .not("iktatoszam", "is", null)
    .is("deleted_at", null)
    .select("id")
    .maybeSingle();

  if (error) {
    return { ok: false, error: "A mentés nem sikerült: " + error.message };
  }
  if (!data) {
    return { ok: false, error: "Ez az irat nem található, vagy még nincs iktatva." };
  }

  revalidatePath("/fizetesek");
  return { ok: true };
}
