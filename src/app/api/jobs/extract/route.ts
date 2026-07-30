import { NextResponse } from "next/server";
import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { runExtraction } from "@/lib/extraction/run";

export const maxDuration = 120;

const MAX_ATTEMPTS = 3;

// Worker endpoint. Protected by a shared secret — not callable from outside.
// Claim is a conditional UPDATE: two concurrent calls cannot both win because
// the second one re-evaluates the WHERE clause after the first commits.
export async function POST(request: Request) {
  if (request.headers.get("x-worker-secret") !== process.env.WORKER_SECRET) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  let documentId: string;
  try {
    const body = await request.json();
    documentId = String(body.documentId ?? "");
  } catch {
    return NextResponse.json({ error: "invalid body" }, { status: 400 });
  }
  if (!documentId) {
    return NextResponse.json({ error: "documentId required" }, { status: 400 });
  }

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

  if (!claimed) {
    return NextResponse.json({ status: "not_claimable" });
  }

  const attempts = (claimed.extraction_attempts ?? 0) + 1;
  await admin.from("document").update({ extraction_attempts: attempts }).eq("id", documentId);

  try {
    await runExtraction(documentId);
    return NextResponse.json({ status: "done" });
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    console.error(`extraction failed for ${documentId} (attempt ${attempts})`, message);

    // Back to 'received' for the sweep to retry, or terminal failure.
    await admin
      .from("document")
      .update({
        processing_status: attempts >= MAX_ATTEMPTS ? "extraction_failed" : "received",
      })
      .eq("id", documentId)
      .eq("processing_status", "extracting");

    return NextResponse.json({ status: "failed", attempts, error: message });
  }
}
