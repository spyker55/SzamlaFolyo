"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";

export type InboxActionResult = { ok: true } | { ok: false; error: string };

// Mirrors MAX_ATTEMPTS in src/lib/jobs/claim.ts: a document that already
// exhausted its retries must not be handed back to the sweep as if it were new.
const MAX_EXTRACTION_ATTEMPTS = 3;

// Discarding is only ever a soft delete, and only before iktatás. The filters
// below say the same thing the database trigger enforces
// (app.protect_iktatott_document) — the trigger is the guarantee, these are
// what turn a refusal into a sentence the user can read.
export async function elvet(documentId: string): Promise<InboxActionResult> {
  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase
    .from("document")
    .update({
      processing_status: "elvetve",
      deleted_at: new Date().toISOString(),
    })
    .eq("id", documentId)
    .is("iktatoszam", null)
    .is("deleted_at", null)
    .select("id")
    .maybeSingle();

  if (error) {
    return { ok: false, error: "Az elvetés nem sikerült: " + error.message };
  }
  if (!data) {
    return {
      ok: false,
      error:
        "Ez az irat nem vethető el — vagy már iktatva van (akkor csak érvényteleníteni lehet), vagy közben valaki más elvetette.",
    };
  }

  revalidatePath("/inbox");
  return { ok: true };
}

// The counterpart, so a misclick is not permanent.
//
// The status it comes back to is derived rather than remembered: restoring
// everything to 'received' would re-run extraction on a document that was
// already read — a second model call and a second extraction row — and would
// throw away a duplicate marking that is still true.
export async function visszaallit(documentId: string): Promise<InboxActionResult> {
  const supabase = await createSupabaseServerClient();

  const { data: doc } = await supabase
    .from("document")
    .select("id, duplicate_of_document_id, extraction_attempts")
    .eq("id", documentId)
    .eq("processing_status", "elvetve")
    .maybeSingle();

  if (!doc) {
    return { ok: false, error: "Ez az irat nem állítható vissza." };
  }

  const { data: extraction } = await supabase
    .from("extraction")
    .select("id")
    .eq("document_id", documentId)
    .is("error", null)
    .not("parsed_fields", "is", null)
    .limit(1)
    .maybeSingle();

  const restored = doc.duplicate_of_document_id
    ? "duplicate"
    : extraction
      ? "needs_review"
      : (doc.extraction_attempts ?? 0) >= MAX_EXTRACTION_ATTEMPTS
        ? "extraction_failed"
        : "received";

  const { data, error } = await supabase
    .from("document")
    .update({ processing_status: restored, deleted_at: null })
    .eq("id", documentId)
    .eq("processing_status", "elvetve")
    .select("id")
    .maybeSingle();

  if (error) {
    return { ok: false, error: "A visszaállítás nem sikerült: " + error.message };
  }
  if (!data) {
    return { ok: false, error: "Ez az irat nem állítható vissza." };
  }

  revalidatePath("/inbox");
  return { ok: true };
}
