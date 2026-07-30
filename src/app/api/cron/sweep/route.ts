import { NextResponse } from "next/server";
import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { kickExtraction } from "@/lib/jobs/kick";

export const maxDuration = 60;

const STALE_RECEIVED_MS = 30 * 1000;
const STALE_EXTRACTING_MS = 5 * 60 * 1000;
const MAX_ATTEMPTS = 3;
const BATCH = 5;

// Runs every minute (vercel.json). Two jobs:
//  1. re-kick documents stuck in 'received' (a lost after()-kick),
//  2. recover documents stuck in 'extracting' (crashed/timed-out worker).
export async function GET(request: Request) {
  const auth = request.headers.get("authorization");
  if (auth !== `Bearer ${process.env.CRON_SECRET}`) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const admin = createSupabaseAdminClient();
  const now = Date.now();

  // Crashed workers: release or fail the claim depending on attempts.
  const { data: stuck } = await admin
    .from("document")
    .select("id, extraction_attempts")
    .eq("processing_status", "extracting")
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

  // Waiting documents: kick a batch sequentially (each call claims for itself).
  const { data: waiting } = await admin
    .from("document")
    .select("id")
    .eq("processing_status", "received")
    .lt("created_at", new Date(now - STALE_RECEIVED_MS).toISOString())
    .order("created_at", { ascending: true })
    .limit(BATCH);

  for (const doc of waiting ?? []) {
    await kickExtraction(doc.id);
  }

  return NextResponse.json({
    released: stuck?.length ?? 0,
    kicked: waiting?.length ?? 0,
  });
}
