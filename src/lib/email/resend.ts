// Attachments do not arrive inside the webhook: they are listed and fetched
// from the Resend API with the received email's id.
//
// Paths confirmed against the official resend-node SDK. The response field
// names are read tolerantly (snake_case and camelCase both accepted) because
// the published documentation could not be reached to pin them down — the
// raw webhook payload is stored either way, so a wrong guess is recoverable
// rather than silent.

const API_BASE = process.env.RESEND_BASE_URL ?? "https://api.resend.com";

export type InboundAttachment = {
  id: string | null;
  filename: string;
  contentType: string;
  downloadUrl: string | null;
  size: number | null;
};

export async function listReceivedAttachments(
  emailId: string,
  apiKey: string
): Promise<InboundAttachment[]> {
  const res = await fetch(`${API_BASE}/emails/receiving/${emailId}/attachments`, {
    headers: { Authorization: `Bearer ${apiKey}` },
  });

  if (!res.ok) {
    throw new Error(`Resend attachment list failed (${res.status}): ${await res.text()}`);
  }

  const body = (await res.json()) as unknown;
  return parseAttachmentList(body);
}

export function parseAttachmentList(body: unknown): InboundAttachment[] {
  const raw = pickArray(body);

  return raw.map((entry) => {
    const a = entry as Record<string, unknown>;
    return {
      id: str(a.id),
      filename: str(a.filename) ?? str(a.name) ?? "melleklet",
      contentType: str(a.content_type) ?? str(a.contentType) ?? "application/octet-stream",
      downloadUrl: str(a.download_url) ?? str(a.downloadUrl) ?? str(a.url),
      size: typeof a.size === "number" ? a.size : null,
    };
  });
}

export async function downloadAttachment(url: string): Promise<Buffer> {
  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`Attachment download failed (${res.status})`);
  }
  return Buffer.from(await res.arrayBuffer());
}

function pickArray(body: unknown): unknown[] {
  if (Array.isArray(body)) return body;
  if (body && typeof body === "object") {
    const data = (body as Record<string, unknown>).data;
    if (Array.isArray(data)) return data;
  }
  return [];
}

function str(v: unknown): string | null {
  return typeof v === "string" && v.length > 0 ? v : null;
}
