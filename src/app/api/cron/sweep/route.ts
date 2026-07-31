import { NextResponse } from "next/server";
import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { claimAndRunExtraction } from "@/lib/jobs/claim";

export const maxDuration = 300;

const STALE_RECEIVED_MS = 30 * 1000;
const STALE_EXTRACTING_MS = 5 * 60 * 1000;
const MAX_ATTEMPTS = 3;
const BATCH = 5;

// Runs every minute (vercel.json). Two jobs:
//  1. extract documents stuck in 'received' (upload after() lost or failed),
//  2. recover documents stuck in 'extracting' (crashed/timed-out run).
// Extraction happens in-process — no HTTP self-call.
export async function GET(request: Request) {
  const auth = request.headers.get("authorization");
  if (auth !== `Bearer ${process.env.CRON_SECRET}`) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const admin = createSupabaseAdminClient();
  const now = Date.now();

  // Crashed runs: release or fail the claim depending on attempts.
  const { data: stuck } = await admin
    .from("document")
    .select("id, extraction_attempts")
    .eq("processing_status", "extracting")
    .is("deleted_at", null)
    .lt("extraction_claimed_at", new Date(now - STALE_EXTRACTING_MS).toISOString());

  for (const doc of stuck ?? []) {
    await admin
      .from("document")
      .update({
        processing_status:
          (doc.extraction_attempts ?? 0) >= MAX_ATTEMPTS ? "extraction_failed" : "received",
      })
      .eq("id", doc.id)
      .eq("processing_status", "extracting");
  }

  // Waiting documents: process a batch (each claim is race-safe on its own).
  const { data: waiting } = await admin
    .from("document")
    .select("id")
    .eq("processing_status", "received")
    // Discarded documents are not waiting for anything.
    .is("deleted_at", null)
    .lt("created_at", new Date(now - STALE_RECEIVED_MS).toISOString())
    .order("created_at", { ascending: true })
    .limit(BATCH);

  const outcomes: Record<string, string> = {};
  for (const doc of waiting ?? []) {
    outcomes[doc.id] = await claimAndRunExtraction(doc.id);
  }

  return NextResponse.json({
    released: stuck?.length ?? 0,
    processed: outcomes,
  });
}
