import { NextResponse, after } from "next/server";
import { claimAndRunExtraction } from "@/lib/jobs/claim";
import { ingestInboundEmail } from "@/lib/email/ingest";
import { verifyResendSignature } from "@/lib/email/signature";

// Attachments are downloaded and extracted while the function stays alive.
export const maxDuration = 300;

export async function POST(request: Request) {
  const secret = process.env.RESEND_WEBHOOK_SECRET;
  if (!secret) {
    // Refuse rather than accept unverified writes into a tenant.
    return NextResponse.json({ error: "webhook not configured" }, { status: 503 });
  }

  // The signature covers the exact bytes, so the body is read as text first
  // and parsed only after it is verified.
  const rawBody = await request.text();

  const verified = verifyResendSignature({
    secret,
    id: request.headers.get("svix-id"),
    timestamp: request.headers.get("svix-timestamp"),
    signature: request.headers.get("svix-signature"),
    body: rawBody,
  });

  if (!verified) {
    return NextResponse.json({ error: "invalid signature" }, { status: 401 });
  }

  let outcome;
  try {
    outcome = await ingestInboundEmail(rawBody);
  } catch (err) {
    // 500 tells Resend to retry; the idempotency key keeps that safe.
    const detail = err instanceof Error ? err.message : String(err);
    return NextResponse.json({ error: detail }, { status: 500 });
  }

  after(async () => {
    for (const id of outcome.documentIds) {
      await claimAndRunExtraction(id);
    }
  });

  return NextResponse.json({
    status: outcome.status,
    documents: outcome.documentIds.length,
  });
}
