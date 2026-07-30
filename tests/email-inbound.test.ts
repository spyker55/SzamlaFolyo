import { createHmac } from "node:crypto";
import { describe, expect, it } from "vitest";
import { findInboxToken, inboxAddress, normalizeAddress } from "@/lib/email/address";
import { verifyResendSignature } from "@/lib/email/signature";
import { parseInboundPayload } from "@/lib/email/payload";
import { parseAttachmentList } from "@/lib/email/resend";

const DOMAIN = "iktato.szamlafolyo.hu";

describe("inbox address", () => {
  it("builds the company address from its token", () => {
    expect(inboxAddress("k7f3x9m2b1c4d5e6", DOMAIN)).toBe(
      "k7f3x9m2b1c4d5e6@iktato.szamlafolyo.hu"
    );
  });

  it("finds our recipient among several", () => {
    expect(
      findInboxToken(
        ["Konyveles <konyveles@ceg.hu>", "abc123@iktato.szamlafolyo.hu"],
        DOMAIN
      )
    ).toBe("abc123");
  });

  it("reads the display-name form", () => {
    expect(findInboxToken(["Számlafolyó <abc123@iktato.szamlafolyo.hu>"], DOMAIN)).toBe(
      "abc123"
    );
  });

  it("ignores plus-addressing so a forwarded copy still lands", () => {
    expect(findInboxToken(["abc123+szamla@iktato.szamlafolyo.hu"], DOMAIN)).toBe("abc123");
  });

  it("is case insensitive", () => {
    expect(findInboxToken(["ABC123@Iktato.Szamlafolyo.HU"], DOMAIN)).toBe("abc123");
  });

  it("does not match a lookalike domain", () => {
    // The token must not be honoured for iktato.szamlafolyo.hu.attacker.com
    // or for the bare main domain.
    expect(findInboxToken(["abc123@iktato.szamlafolyo.hu.attacker.com"], DOMAIN)).toBeNull();
    expect(findInboxToken(["abc123@szamlafolyo.hu"], DOMAIN)).toBeNull();
  });

  it("returns null when we are not a recipient at all", () => {
    expect(findInboxToken(["valaki@maskonyvelo.hu"], DOMAIN)).toBeNull();
  });

  it("strips the display name from an address", () => {
    expect(normalizeAddress("Nethely Kft. <szamla@nethely.hu>")).toBe("szamla@nethely.hu");
    expect(normalizeAddress("nem cim")).toBeNull();
  });
});

// Signing helper mirroring what Resend/Svix does, so the verifier is tested
// against a real signature rather than a hand-written constant.
function sign(secret: string, id: string, timestamp: number, body: string): string {
  const key = Buffer.from(secret.replace(/^whsec_/, ""), "base64");
  const mac = createHmac("sha256", key).update(`${id}.${timestamp}.${body}`).digest("base64");
  return `v1,${mac}`;
}

describe("webhook signature", () => {
  const secret = "whsec_" + Buffer.from("titkos-kulcs-a-teszthez").toString("base64");
  const id = "msg_123";
  const now = 1_800_000_000;
  const body = JSON.stringify({ type: "email.received" });

  it("accepts a correctly signed delivery", () => {
    expect(
      verifyResendSignature({
        secret,
        id,
        timestamp: String(now),
        signature: sign(secret, id, now, body),
        body,
        nowSeconds: now,
      })
    ).toBe(true);
  });

  it("accepts when several signatures are offered during a rotation", () => {
    const signature = `v1,${Buffer.from("masik").toString("base64")} ${sign(secret, id, now, body)}`;
    expect(
      verifyResendSignature({ secret, id, timestamp: String(now), signature, body, nowSeconds: now })
    ).toBe(true);
  });

  it("refuses a tampered body", () => {
    expect(
      verifyResendSignature({
        secret,
        id,
        timestamp: String(now),
        signature: sign(secret, id, now, body),
        body: JSON.stringify({ type: "email.received", evil: true }),
        nowSeconds: now,
      })
    ).toBe(false);
  });

  it("refuses a replayed delivery from six minutes ago", () => {
    const old = now - 6 * 60;
    expect(
      verifyResendSignature({
        secret,
        id,
        timestamp: String(old),
        signature: sign(secret, id, old, body),
        body,
        nowSeconds: now,
      })
    ).toBe(false);
  });

  it("refuses a wrong secret", () => {
    const other = "whsec_" + Buffer.from("masik-kulcs").toString("base64");
    expect(
      verifyResendSignature({
        secret,
        id,
        timestamp: String(now),
        signature: sign(other, id, now, body),
        body,
        nowSeconds: now,
      })
    ).toBe(false);
  });

  it("refuses missing headers instead of letting the request through", () => {
    for (const missing of ["id", "timestamp", "signature"] as const) {
      const input = {
        secret,
        id: missing === "id" ? null : id,
        timestamp: missing === "timestamp" ? null : String(now),
        signature: missing === "signature" ? null : sign(secret, id, now, body),
        body,
        nowSeconds: now,
      };
      expect(verifyResendSignature(input), missing).toBe(false);
    }
  });
});

describe("payload reading", () => {
  it("reads the documented shape", () => {
    expect(
      parseInboundPayload({
        type: "email.received",
        data: {
          email_id: "re_abc",
          from: "szamla@nethely.hu",
          to: ["abc123@iktato.szamlafolyo.hu"],
          subject: "Számla",
        },
      })
    ).toEqual({
      emailId: "re_abc",
      messageId: "re_abc",
      from: "szamla@nethely.hu",
      to: ["abc123@iktato.szamlafolyo.hu"],
      subject: "Számla",
    });
  });

  it("copes with camelCase and a single-string recipient", () => {
    const parsed = parseInboundPayload({ data: { emailId: "re_x", to: "a@b.hu, c@d.hu" } });
    expect(parsed.emailId).toBe("re_x");
    expect(parsed.to).toEqual(["a@b.hu", "c@d.hu"]);
  });

  it("returns nulls rather than throwing on an unexpected shape", () => {
    expect(parseInboundPayload({ hello: "world" })).toEqual({
      emailId: null,
      messageId: null,
      from: null,
      to: [],
      subject: null,
    });
  });
});

describe("attachment list reading", () => {
  it("reads snake_case, camelCase and a bare array alike", () => {
    const expected = {
      id: "att_1",
      filename: "szamla.pdf",
      contentType: "application/pdf",
      downloadUrl: "https://example.test/a.pdf",
      size: 1234,
    };

    expect(
      parseAttachmentList({
        data: [
          {
            id: "att_1",
            filename: "szamla.pdf",
            content_type: "application/pdf",
            download_url: "https://example.test/a.pdf",
            size: 1234,
          },
        ],
      })
    ).toEqual([expected]);

    expect(
      parseAttachmentList([
        {
          id: "att_1",
          filename: "szamla.pdf",
          contentType: "application/pdf",
          downloadUrl: "https://example.test/a.pdf",
          size: 1234,
        },
      ])
    ).toEqual([expected]);
  });

  it("returns an empty list for an unexpected shape", () => {
    expect(parseAttachmentList({ nope: true })).toEqual([]);
  });
});
