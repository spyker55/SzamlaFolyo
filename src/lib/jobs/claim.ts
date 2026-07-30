import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { runExtraction } from "@/lib/extraction/run";

const MAX_ATTEMPTS = 3;

// Claim + extract, in-process. The claim is a conditional UPDATE: two
// concurrent callers cannot both win because the loser re-evaluates the WHERE
// clause after the winner commits. On failure the document goes back to
// 'received' for the sweep to retry, or to 'extraction_failed' after the
// last attempt.
export async function claimAndRunExtraction(
  documentId: string
): Promise<"done" | "not_claimable" | "failed"> {
  const admin = createSupabaseAdminClient();

  const { data: claimed } = await admin
    .from("document")
    .update({
      processing_status: "extracting",
      extraction_claimed_at: new Date().toISOString(),
    })
    .eq("id", documentId)
    .eq("processing_status", "received")
    .select("id, extraction_attempts")
    .maybeSingle();

  if (!claimed) return "not_claimable";

  const attempts = (claimed.extraction_attempts ?? 0) + 1;
  await admin
    .from("document")
    .update({ extraction_attempts: attempts })
    .eq("id", documentId);

  try {
    await runExtraction(documentId);
    return "done";
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    console.error(`extraction failed for ${documentId} (attempt ${attempts})`, message);

    await admin
      .from("document")
      .update({
        processing_status: attempts >= MAX_ATTEMPTS ? "extraction_failed" : "received",
      })
      .eq("id", documentId)
      .eq("processing_status", "extracting");

    return "failed";
  }
}
