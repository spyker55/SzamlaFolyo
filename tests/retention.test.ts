import { describe, expect, it } from "vitest";
import {
  ensureCompany,
  hasLiveCredentials,
  insertReviewableDocument,
  signedInClient,
  USER_A_EMAIL,
} from "./helpers";

// "Iktatott iratot fizikailag törölni tilos, csak deleted_at / érvénytelenítés"
// used to rest on nobody trying: document_update lets a member write every
// column, so a plain UPDATE could soft-delete an iktatott irat or rewrite its
// iktatószám. app.protect_iktatott_document() is what actually forbids it, and
// these run through the public API to prove it holds for a normal member.

describe.skipIf(!hasLiveCredentials)("retention rules (live project)", () => {
  async function iktatottDocument() {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const documentId = await insertReviewableDocument(client, companyId, "Megtartás teszt");
    const { data, error } = await client.rpc("iktat_document", {
      p_document_id: documentId,
      p_values: {},
    });
    expect(error).toBeNull();
    return { client, companyId, documentId, iktatoszam: (data as { iktatoszam: string }).iktatoszam };
  }

  it("refuses to soft-delete an iktatott irat", async () => {
    const { client, documentId } = await iktatottDocument();

    const { error } = await client
      .from("document")
      .update({ deleted_at: new Date().toISOString() })
      .eq("id", documentId);

    expect(error).not.toBeNull();
    expect(error!.message).toContain("cannot be deleted");

    const { data } = await client
      .from("document")
      .select("deleted_at")
      .eq("id", documentId)
      .single();
    expect(data!.deleted_at).toBeNull();
  });

  it("refuses to rewrite the iktatoszam, ugy or alszam", async () => {
    const { client, documentId, iktatoszam } = await iktatottDocument();

    for (const patch of [{ iktatoszam: "IKT/999-9/2026" }, { alszam: 7 }, { ugy_id: null }]) {
      const { error } = await client.from("document").update(patch).eq("id", documentId);
      expect(error, JSON.stringify(patch)).not.toBeNull();
      expect(error!.message).toContain("keeps its iktatoszam");
    }

    const { data } = await client
      .from("document")
      .select("iktatoszam")
      .eq("id", documentId)
      .single();
    expect(data!.iktatoszam).toBe(iktatoszam);
  });

  it("allows érvénytelenítés, and nothing else, after iktatás", async () => {
    const { client, documentId } = await iktatottDocument();

    const backwards = await client
      .from("document")
      .update({ processing_status: "needs_review" })
      .eq("id", documentId);
    expect(backwards.error).not.toBeNull();

    // The one permitted transition — and the iktatószám survives it, because an
    // érvénytelenített irat still occupies its number.
    const { error } = await client
      .from("document")
      .update({ processing_status: "ervenytelenitve" })
      .eq("id", documentId);
    expect(error).toBeNull();

    const { data } = await client
      .from("document")
      .select("iktatoszam, deleted_at")
      .eq("id", documentId)
      .single();
    expect(data!.iktatoszam).not.toBeNull();
    expect(data!.deleted_at).toBeNull();
  });

  it("lets a document be discarded and restored before iktatás", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const documentId = await insertReviewableDocument(client, companyId, "Elvetés teszt");

    const discard = await client
      .from("document")
      .update({ processing_status: "elvetve", deleted_at: new Date().toISOString() })
      .eq("id", documentId);
    expect(discard.error).toBeNull();

    // Gone from the Beérkező's query, but still there.
    const { data: hidden } = await client
      .from("document")
      .select("id")
      .eq("id", documentId)
      .is("deleted_at", null);
    expect(hidden).toEqual([]);

    const restore = await client
      .from("document")
      .update({ processing_status: "needs_review", deleted_at: null })
      .eq("id", documentId);
    expect(restore.error).toBeNull();

    const { data } = await client
      .from("document")
      .select("deleted_at, iktatoszam")
      .eq("id", documentId)
      .single();
    expect(data!.deleted_at).toBeNull();
    // Discarding never allocated a number, so nothing was burned.
    expect(data!.iktatoszam).toBeNull();
  });
});
