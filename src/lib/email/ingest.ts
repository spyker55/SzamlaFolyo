import { createSupabaseAdminClient } from "@/lib/supabase/admin";
import { storeIncomingFile } from "@/lib/upload/store";
import { findInboxToken, normalizeAddress } from "./address";
import { parseInboundPayload } from "./payload";
import { downloadAttachment, listReceivedAttachments } from "./resend";

export type IngestOutcome = {
  status: "processed" | "no_attachment" | "rejected" | "duplicate" | "unknown_recipient";
  documentIds: string[];
  inboundEmailId?: string;
  detail?: string;
};

// An inbound e-mail is an unauthenticated write path into a tenant, so
// nothing here trusts the message: the recipient token decides the company,
// the sender is only ever recorded (never given authority), and no document
// is ever iktatva automatically.
export async function ingestInboundEmail(rawBody: string): Promise<IngestOutcome> {
  const payload: unknown = JSON.parse(rawBody);
  const parsed = parseInboundPayload(payload);
  const admin = createSupabaseAdminClient();

  const token = findInboxToken(parsed.to);
  if (!token) {
    // Not addressed to any of our inboxes; nothing to attribute it to.
    return { status: "unknown_recipient", documentIds: [], detail: "no inbox token" };
  }

  const { data: company } = await admin
    .from("company")
    .select("id")
    .eq("inbox_token", token)
    .maybeSingle();

  if (!company) {
    return { status: "unknown_recipient", documentIds: [], detail: "unknown token" };
  }

  const companyId = company.id as string;
  const messageId = parsed.messageId ?? `no-id-${Date.now()}`;
  const from = parsed.from ? normalizeAddress(parsed.from) : null;

  // Record the sender before deciding anything, and let a previously accepted
  // sender count as known. Trust is earned by iktatás (see the trigger in
  // 20260730000009_email_ingest.sql), never by the message claiming it.
  let senderKnown = false;
  if (from) {
    const { data: sender } = await admin
      .from("email_sender")
      .select("id, trusted")
      .eq("company_id", companyId)
      .eq("address", from)
      .maybeSingle();

    if (sender) {
      senderKnown = Boolean(sender.trusted);
      await admin
        .from("email_sender")
        .update({ last_seen_at: new Date().toISOString() })
        .eq("id", sender.id);
    } else {
      await admin.from("email_sender").insert({ company_id: companyId, address: from });
    }
  }

  // The row is created before the attachments are touched: the raw payload
  // survives a failure, and the unique (company_id, provider_message_id)
  // makes a repeated webhook delivery a no-op instead of a second document.
  const { data: inbound, error: insertErr } = await admin
    .from("inbound_email")
    .insert({
      company_id: companyId,
      provider_message_id: messageId,
      mail_from: from,
      mail_to: parsed.to.join(", "),
      subject: parsed.subject,
      sender_known: senderKnown,
      status: "received",
      raw_payload: payload,
    })
    .select("id")
    .single();

  let inboundEmailId: string;

  if (insertErr) {
    if (insertErr.code !== "23505") {
      throw new Error(`inbound_email insert failed: ${insertErr.message}`);
    }

    // 23505 = unique violation: we have seen this delivery before. A run that
    // ended in 'rejected' is worth retrying though — that is exactly what a
    // replay is for, and refusing it would leave the message permanently
    // stuck with no way to recover it.
    const { data: existing } = await admin
      .from("inbound_email")
      .select("id, status")
      .eq("company_id", companyId)
      .eq("provider_message_id", messageId)
      .single();

    if (!existing || existing.status !== "rejected") {
      return { status: "duplicate", documentIds: [], detail: messageId };
    }

    inboundEmailId = existing.id as string;
    await admin
      .from("inbound_email")
      .update({ status: "received", error: null, raw_payload: payload })
      .eq("id", inboundEmailId);
  } else {
    inboundEmailId = inbound.id as string;
  }

  try {
    const apiKey = process.env.RESEND_API_KEY;
    if (!apiKey) throw new Error("RESEND_API_KEY is not set");
    if (!parsed.emailId) throw new Error("payload carries no received email id");

    const attachments = await listReceivedAttachments(parsed.emailId, apiKey);

    // The payload marks which parts are embedded in the message body. Without
    // this every signature logo would arrive as a document of its own.
    const inlineIds = new Set(
      parsed.attachments
        .filter((a) => a.disposition?.toLowerCase() === "inline" && a.id)
        .map((a) => a.id as string)
    );

    const documentIds: string[] = [];
    let rejected = 0;

    for (const attachment of attachments) {
      if (attachment.id && inlineIds.has(attachment.id)) continue;
      if (!attachment.downloadUrl) {
        rejected++;
        continue;
      }
      const bytes = await downloadAttachment(attachment.downloadUrl);
      const result = await storeIncomingFile({
        admin,
        companyId,
        bytes,
        filename: attachment.filename,
        mimeType: attachment.contentType,
        inboundEmailId,
      });

      if (result.status === "created" && result.documentId) {
        documentIds.push(result.documentId);
      } else if (result.status === "rejected") {
        rejected++;
      }
    }

    const status = documentIds.length > 0 ? "processed" : "no_attachment";
    await admin
      .from("inbound_email")
      .update({
        status,
        attachment_count: attachments.length,
        document_count: documentIds.length,
        error:
          rejected > 0 ? `${rejected} melléklet nem dolgozható fel (típus vagy méret).` : null,
      })
      .eq("id", inboundEmailId);

    return { status, documentIds, inboundEmailId };
  } catch (err) {
    const detail = err instanceof Error ? err.message : String(err);
    await admin
      .from("inbound_email")
      .update({ status: "rejected", error: detail })
      .eq("id", inboundEmailId);

    return { status: "rejected", documentIds: [], inboundEmailId, detail };
  }
}
