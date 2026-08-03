// Authenticating a request to the Online Számla API.
//
// Three values go into every request, and each of them is exact in a way that
// produces an unhelpful error when it is wrong — NAV answers INVALID_SECURITY_USER
// whether the password is wrong, the signature is wrong, or the clock is off.
// So the rules are written down here rather than inferred from a failure:
//
//   passwordHash     SHA-512 of the technical user's password, uppercase hex.
//   requestSignature SHA3-512 of requestId + timestamp + signing key, uppercase
//                    hex. Note SHA-3, not SHA-2: API 2.0 used SHA-512 here and
//                    3.0 changed it, which is the single most common reason a
//                    working 2.0 integration stops authenticating.
//   timestamp        The header carries millisecond precision in UTC; the
//                    signature uses the *same instant* with the separators and
//                    the milliseconds removed. They must be one Date, not two
//                    calls to now() — a request built across a second boundary
//                    signs a timestamp it did not send.
//
// The signing key (aláíró kulcs) is not the password. It is issued separately
// in the Online Számla portal when the technical user is created, and it never
// travels over the wire: only the hash it participates in does.

import { createHash, randomBytes } from "node:crypto";

// A NAV requestId is [+a-zA-Z0-9_]{1,30} and has to be unique for the taxpayer
// forever. Time plus randomness: the timestamp makes replays orderable by eye
// in a support ticket, the random tail makes two requests in the same second
// distinct.
const ID_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

export function newRequestId(at: Date = new Date(), random = randomBytes): string {
  const bytes = random(8);
  let tail = "";
  for (const b of bytes) tail += ID_ALPHABET[b % ID_ALPHABET.length];
  return `SZF${signatureTimestamp(at)}${tail}`; // 3 + 14 + 8 = 25 characters
}

export function isValidRequestId(id: string): boolean {
  return /^[+a-zA-Z0-9_]{1,30}$/.test(id);
}

// 2026-08-03T09:15:04.123Z — what goes in <common:timestamp>.
export function headerTimestamp(at: Date): string {
  return at.toISOString().replace(/\.(\d{3})\d*Z$/, ".$1Z");
}

// 20260803091504 — the same instant, as the signature wants it.
export function signatureTimestamp(at: Date): string {
  return at.toISOString().slice(0, 19).replace(/[-:T]/g, "");
}

export function passwordHash(password: string): string {
  return createHash("sha512").update(password, "utf8").digest("hex").toUpperCase();
}

export function requestSignature(requestId: string, at: Date, signKey: string): string {
  const input = `${requestId}${signatureTimestamp(at)}${signKey}`;
  return createHash("sha3-512").update(input, "utf8").digest("hex").toUpperCase();
}
