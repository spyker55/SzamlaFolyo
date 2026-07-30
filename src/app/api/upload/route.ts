import { createHash } from "node:crypto";
import { NextResponse, after } from "next/server";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { claimAndRunExtraction } from "@/lib/jobs/claim";

const ALLOWED_MIME = new Set([
  "application/pdf",
  "image/jpeg",
  "image/png",
  "image/webp",
]);

const MAX_FILE_BYTES = 20 * 1024 * 1024;

// The response returns immediately; after() keeps the function alive while
// the uploaded documents are extracted in-process (no HTTP self-call — that
// would die on Vercel Deployment Protection).
export const maxDuration = 300;

type UploadResult = {
  filename: string;
  status: "created" | "duplicate" | "rejected";
  documentId?: string;
  duplicateOfIktatoszam?: string | null;
  reason?: string;
};

export async function POST(request: Request) {
  const supabase = await createSupabaseServerClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const { data: member } = await supabase
    .from("company_member")
    .select("company_id")
    .limit(1)
    .maybeSingle();

  if (!member) {
    return NextResponse.json({ error: "no company" }, { status: 403 });
  }

  const companyId = member.company_id as string;
  const formData = await request.formData();
  const files = formData.getAll("files").filter((f): f is File => f instanceof File);

  if (files.length === 0) {
    return NextResponse.json({ error: "no files" }, { status: 400 });
  }

  const admin = createSupabaseAdminClient();
  const results: UploadResult[] = [];
  const toExtract: string[] = [];

  for (const file of files) {
    if (!ALLOWED_MIME.has(file.type)) {
      results.push({
        filename: file.name,
        status: "rejected",
        reason: "Csak PDF, JPEG, PNG vagy WebP tölthető fel.",
      });
      continue;
    }
    if (file.size > MAX_FILE_BYTES) {
      results.push({
        filename: file.name,
        status: "rejected",
        reason: "A fájl nagyobb 20 MB-nál.",
      });
      continue;
    }

    const bytes = Buffer.from(await file.arrayBuffer());
    // The hash is computed server-side; a client-supplied hash is never trusted.
    const sha256 = createHash("sha256").update(bytes).digest("hex");

    // Duplicate within the company? Create the document row as a marked
    // duplicate (auditable in the inbox) but do not re-upload the blob and
    // never let it get an iktatoszam.
    const { data: existing } = await admin
      .from("document_file")
      .select("document_id, storage_path, document:document_id (iktatoszam, deleted_at)")
      .eq("company_id", companyId)
      .eq("sha256", sha256)
      .limit(1)
      .maybeSingle();

    const original = existing?.document as unknown as
      | { iktatoszam: string | null; deleted_at: string | null }
      | null;

    if (existing && !original?.deleted_at) {
      const { data: dupDoc, error: dupErr } = await admin
        .from("document")
        .insert({
          company_id: companyId,
          processing_status: "duplicate",
          duplicate_of_document_id: existing.document_id,
          source: "upload",
          created_by: user.id,
        })
        .select("id")
        .single();

      if (dupErr) {
        results.push({ filename: file.name, status: "rejected", reason: dupErr.message });
        continue;
      }

      await admin.from("document_file").insert({
        company_id: companyId,
        document_id: dupDoc.id,
        storage_path: existing.storage_path,
        original_filename: file.name,
        mime_type: file.type,
        sha256,
      });

      results.push({
        filename: file.name,
        status: "duplicate",
        documentId: dupDoc.id,
        duplicateOfIktatoszam: original?.iktatoszam ?? null,
      });
      continue;
    }

    const { data: doc, error: docErr } = await admin
      .from("document")
      .insert({
        company_id: companyId,
        processing_status: "received",
        source: "upload",
        created_by: user.id,
      })
      .select("id")
      .single();

    if (docErr) {
      results.push({ filename: file.name, status: "rejected", reason: docErr.message });
      continue;
    }

    const safeName = file.name.replace(/[^a-zA-Z0-9._-]/g, "_");
    const storagePath = `${companyId}/${doc.id}/${safeName}`;

    const { error: storageErr } = await admin.storage
      .from("iratok")
      .upload(storagePath, bytes, { contentType: file.type, upsert: false });

    if (storageErr) {
      // No orphaned drafts: mark the row invalid instead of deleting (iktatott
      // documents are never deleted; drafts keep the same discipline).
      await admin
        .from("document")
        .update({ processing_status: "extraction_failed", deleted_at: new Date().toISOString() })
        .eq("id", doc.id);
      results.push({ filename: file.name, status: "rejected", reason: storageErr.message });
      continue;
    }

    await admin.from("document_file").insert({
      company_id: companyId,
      document_id: doc.id,
      storage_path: storagePath,
      original_filename: file.name,
      mime_type: file.type,
      sha256,
    });

    results.push({ filename: file.name, status: "created", documentId: doc.id });
    toExtract.push(doc.id);
  }

  // Extract after the response is sent; the cron sweep is the safety net.
  after(async () => {
    for (const id of toExtract) {
      await claimAndRunExtraction(id);
    }
  });

  return NextResponse.json({ results });
}
