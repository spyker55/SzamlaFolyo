import { createHash } from "node:crypto";
import type { SupabaseClient } from "@supabase/supabase-js";

// One place where an incoming file becomes a document, whether it arrived by
// browser upload or by e-mail. The duplicate rules must not drift apart
// between the two paths, so they live here rather than in each caller.

export const ALLOWED_MIME = new Set([
  "application/pdf",
  "image/jpeg",
  "image/png",
  "image/webp",
]);

export const MAX_FILE_BYTES = 20 * 1024 * 1024;

export type StoreResult = {
  filename: string;
  status: "created" | "duplicate" | "rejected";
  documentId?: string;
  duplicateOfIktatoszam?: string | null;
  reason?: string;
};

export type StoreParams = {
  admin: SupabaseClient;
  companyId: string;
  bytes: Buffer;
  filename: string;
  mimeType: string;
  createdBy?: string | null;
  inboundEmailId?: string | null;
};

export async function storeIncomingFile(params: StoreParams): Promise<StoreResult> {
  const { admin, companyId, bytes, filename, mimeType, createdBy, inboundEmailId } = params;

  if (!ALLOWED_MIME.has(mimeType)) {
    return {
      filename,
      status: "rejected",
      reason: "Csak PDF, JPEG, PNG vagy WebP tölthető fel.",
    };
  }
  if (bytes.length > MAX_FILE_BYTES) {
    return { filename, status: "rejected", reason: "A fájl nagyobb 20 MB-nál." };
  }

  // The hash is computed here; a client-supplied hash is never trusted.
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
        source: inboundEmailId ? "email" : "upload",
        created_by: createdBy ?? null,
        inbound_email_id: inboundEmailId ?? null,
      })
      .select("id")
      .single();

    if (dupErr) return { filename, status: "rejected", reason: dupErr.message };

    await admin.from("document_file").insert({
      company_id: companyId,
      document_id: dupDoc.id,
      storage_path: existing.storage_path,
      original_filename: filename,
      mime_type: mimeType,
      sha256,
    });

    return {
      filename,
      status: "duplicate",
      documentId: dupDoc.id,
      duplicateOfIktatoszam: original?.iktatoszam ?? null,
    };
  }

  const { data: doc, error: docErr } = await admin
    .from("document")
    .insert({
      company_id: companyId,
      processing_status: "received",
      source: inboundEmailId ? "email" : "upload",
      created_by: createdBy ?? null,
      inbound_email_id: inboundEmailId ?? null,
    })
    .select("id")
    .single();

  if (docErr) return { filename, status: "rejected", reason: docErr.message };

  const safeName = filename.replace(/[^a-zA-Z0-9._-]/g, "_");
  const storagePath = `${companyId}/${doc.id}/${safeName}`;

  const { error: storageErr } = await admin.storage
    .from("iratok")
    .upload(storagePath, bytes, { contentType: mimeType, upsert: false });

  if (storageErr) {
    // No orphaned drafts: mark the row invalid instead of deleting (iktatott
    // documents are never deleted; drafts keep the same discipline).
    await admin
      .from("document")
      .update({ processing_status: "extraction_failed", deleted_at: new Date().toISOString() })
      .eq("id", doc.id);
    return { filename, status: "rejected", reason: storageErr.message };
  }

  await admin.from("document_file").insert({
    company_id: companyId,
    document_id: doc.id,
    storage_path: storagePath,
    original_filename: filename,
    mime_type: mimeType,
    sha256,
  });

  return { filename, status: "created", documentId: doc.id };
}
