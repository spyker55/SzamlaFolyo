// The email.received payload shape could not be verified against the
// published docs (they are unreachable from the build environment), so the
// fields are read tolerantly and the raw payload is always stored alongside.
// The first real delivery confirms or corrects this without losing anything.

export type PayloadAttachment = {
  id: string | null;
  filename: string | null;
  contentType: string | null;
  // "inline" marks the logos and images embedded in the message body — a
  // signature logo must not become an iratcsomó.
  disposition: string | null;
};

export type InboundPayload = {
  emailId: string | null;
  messageId: string | null;
  from: string | null;
  to: string[];
  subject: string | null;
  attachments: PayloadAttachment[];
};

export function parseInboundPayload(body: unknown): InboundPayload {
  const root = asRecord(body);
  const data = asRecord(root.data) ?? root;

  const emailId = str(data.email_id) ?? str(data.emailId) ?? str(data.id);

  // received_for is the address the message was actually delivered to, which
  // is what identifies the tenant. "to" misses it whenever we are bcc'd or
  // the mail was forwarded, so it is only the fallback.
  const deliveredTo = list(data.received_for).concat(list(data.receivedFor));
  const recipients = deliveredTo.length > 0 ? deliveredTo : list(data.to);

  return {
    emailId,
    // Prefer the provider's own id for idempotency; fall back to the RFC
    // message-id so a delivery is never treated as new just because the
    // field name differs.
    messageId: emailId ?? str(data.message_id) ?? str(data.messageId),
    from: str(data.from) ?? str(data.sender),
    to: recipients,
    subject: str(data.subject),
    attachments: attachmentList(data.attachments),
  };
}

function attachmentList(v: unknown): PayloadAttachment[] {
  if (!Array.isArray(v)) return [];
  return v.map((entry) => {
    const a = asRecord(entry);
    return {
      id: str(a.id),
      filename: str(a.filename),
      contentType: str(a.content_type) ?? str(a.contentType),
      disposition: str(a.content_disposition) ?? str(a.contentDisposition),
    };
  });
}

function list(v: unknown): string[] {
  if (Array.isArray(v)) return v.filter((x): x is string => typeof x === "string");
  if (typeof v === "string") return v.split(",").map((s) => s.trim()).filter(Boolean);
  return [];
}

function asRecord(v: unknown): Record<string, unknown> {
  return v && typeof v === "object" ? (v as Record<string, unknown>) : {};
}

function str(v: unknown): string | null {
  return typeof v === "string" && v.length > 0 ? v : null;
}
