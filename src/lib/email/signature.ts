import { createHmac, timingSafeEqual } from "node:crypto";

// Resend signs webhooks the Svix way: the signed content is
// "<id>.<timestamp>.<body>", HMAC-SHA256 with the secret that follows the
// "whsec_" prefix (base64), and the header may carry several space-separated
// "v1,<signature>" pairs during a secret rotation.
//
// Verified by hand rather than with the svix package: one less dependency on
// the one unauthenticated write path into a tenant, and it can be unit-tested
// without network access.

// Anything older than this is refused, so a captured delivery cannot be
// replayed later.
const TOLERANCE_SECONDS = 5 * 60;

export type SignatureInput = {
  secret: string;
  id: string | null;
  timestamp: string | null;
  signature: string | null;
  body: string;
  nowSeconds?: number;
};

export function verifyResendSignature(input: SignatureInput): boolean {
  const { secret, id, timestamp, signature, body } = input;
  if (!secret || !id || !timestamp || !signature) return false;

  const sent = Number(timestamp);
  if (!Number.isFinite(sent)) return false;

  const now = input.nowSeconds ?? Math.floor(Date.now() / 1000);
  if (Math.abs(now - sent) > TOLERANCE_SECONDS) return false;

  const key = Buffer.from(secret.replace(/^whsec_/, ""), "base64");
  const expected = createHmac("sha256", key)
    .update(`${id}.${timestamp}.${body}`)
    .digest();

  for (const part of signature.split(" ")) {
    const [version, value] = part.split(",");
    if (version !== "v1" || !value) continue;

    const given = Buffer.from(value, "base64");
    if (given.length === expected.length && timingSafeEqual(given, expected)) {
      return true;
    }
  }
  return false;
}
