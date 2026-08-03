// The NAV technical user's password and signing key, at rest.
//
// These two strings authenticate as the company at the tax authority. They
// cannot be hashed — every request needs the original — so the only honest
// options are "in the database in the clear" and "in the database encrypted
// with a key that is not in the database". This is the second one.
//
// AES-256-GCM, key from NAV_SECRET_KEY in the environment, with the company_id
// as additional authenticated data. Two consequences worth having:
//
//   - a database dump, a leaked read-only credential or a mis-set RLS policy
//     yields ciphertext, because the key lives in Vercel and not in Postgres;
//   - a ciphertext copied into another company's row fails to decrypt rather
//     than authenticating as the company it was stolen from. GCM verifies the
//     AAD, so the tenancy is part of what is authenticated, not a comment.
//
// Rotating NAV_SECRET_KEY makes every stored credential undecryptable. That is
// the correct failure — the screen says the connection has to be set up again,
// and nothing silently authenticates with a key nobody meant to still be using.

import { createCipheriv, createDecipheriv, randomBytes } from "node:crypto";

const VERSION = "v1";
const IV_BYTES = 12; // GCM's standard nonce length; anything else is a footgun.

function keyFromEnv(): Buffer {
  const raw = process.env.NAV_SECRET_KEY;
  if (!raw) {
    throw new Error("NAV_SECRET_KEY nincs beállítva");
  }
  const key = Buffer.from(raw, "base64");
  if (key.length !== 32) {
    throw new Error("NAV_SECRET_KEY nem 32 bájtos base64 kulcs");
  }
  return key;
}

export function navSecretKeyConfigured(): boolean {
  try {
    keyFromEnv();
    return true;
  } catch {
    return false;
  }
}

export function encryptSecret(plain: string, companyId: string): string {
  const iv = randomBytes(IV_BYTES);
  const cipher = createCipheriv("aes-256-gcm", keyFromEnv(), iv);
  cipher.setAAD(Buffer.from(companyId, "utf8"));
  const ct = Buffer.concat([cipher.update(plain, "utf8"), cipher.final()]);
  const tag = cipher.getAuthTag();
  return [VERSION, iv.toString("base64"), tag.toString("base64"), ct.toString("base64")].join(".");
}

export function decryptSecret(stored: string, companyId: string): string {
  const parts = stored.split(".");
  if (parts.length !== 4 || parts[0] !== VERSION) {
    throw new Error("A tárolt NAV-titok formátuma ismeretlen");
  }
  const [, ivB64, tagB64, ctB64] = parts;
  const decipher = createDecipheriv("aes-256-gcm", keyFromEnv(), Buffer.from(ivB64, "base64"));
  decipher.setAAD(Buffer.from(companyId, "utf8"));
  decipher.setAuthTag(Buffer.from(tagB64, "base64"));
  return Buffer.concat([
    decipher.update(Buffer.from(ctB64, "base64")),
    decipher.final(),
  ]).toString("utf8");
}

// For the setup screen: never show a secret back, but do show that one is
// stored and roughly which one, so a wrong paste is noticeable without the
// value being readable.
export function secretHint(plain: string): string {
  const trimmed = plain.trim();
  if (trimmed.length <= 4) return "••••";
  return `${"•".repeat(6)}${trimmed.slice(-3)}`;
}
