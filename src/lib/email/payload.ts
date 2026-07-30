// The email.received payload shape could not be verified against the
// published docs (they are unreachable from the build environment), so the
// fields are read tolerantly and the raw payload is always stored alongside.
// The first real delivery confirms or corrects this without losing anything.

export type InboundPayload = {
  emailId: string | null;
  messageId: string | null;
  from: string | null;
  to: string[];
  subject: string | null;
};

export function parseInboundPayload(body: unknown): InboundPayload {
  const root = asRecord(body);
  const data = asRecord(root.data) ?? root;

  const emailId = str(data.email_id) ?? str(data.emailId) ?? str(data.id);

  return {
    emailId,
    // Prefer the provider's own id for idempotency; fall back to the RFC
    // message-id so a delivery is never treated as new just because the
    // field name differs.
    messageId: emailId ?? str(data.message_id) ?? str(data.messageId),
    from: str(data.from) ?? str(data.sender),
    to: list(data.to),
    subject: str(data.subject),
  };
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
