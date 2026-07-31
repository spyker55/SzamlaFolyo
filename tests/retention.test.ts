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

  it("refuses to move an iktatott irat anywhere but ervenytelenitve", async () => {
    const { client, documentId } = await iktatottDocument();

    const { error } = await client
      .from("document")
      .update({ processing_status: "needs_review" })
      .eq("id", documentId);
    expect(error).not.toBeNull();
    expect(error!.message).toContain("can only move to ervenytelenitve");
  });

  it("érvényteleníti az iratot, indoklással, az iktatószám megtartásával", async () => {
    const { client, documentId, iktatoszam } = await iktatottDocument();

    const { error } = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "Téves iktatás, az irat a másik céghez tartozik.",
    });
    expect(error).toBeNull();

    const { data } = await client
      .from("document")
      .select(
        "processing_status, iktatoszam, deleted_at, ervenytelenites_indoka, ervenytelenitve_at, ervenytelenitette"
      )
      .eq("id", documentId)
      .single();

    expect(data!.processing_status).toBe("ervenytelenitve");
    // The number stays occupied: reissuing it would make the register lie.
    expect(data!.iktatoszam).toBe(iktatoszam);
    expect(data!.deleted_at).toBeNull();
    expect(data!.ervenytelenites_indoka).toContain("Téves iktatás");
    expect(data!.ervenytelenitve_at).not.toBeNull();
    expect(data!.ervenytelenitette).not.toBeNull();
  });

  it("requires a reason, and refuses a second érvénytelenítés", async () => {
    const { client, documentId } = await iktatottDocument();

    const empty = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "   ",
    });
    expect(empty.error).not.toBeNull();
    expect(empty.error!.message).toContain("requires a reason");

    const tooShort = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "hiba",
    });
    expect(tooShort.error).not.toBeNull();

    const first = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "Duplikátum, tévedésből iktatva.",
    });
    expect(first.error).toBeNull();

    const second = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "Még egyszer, másik indokkal.",
    });
    expect(second.error).not.toBeNull();
    expect(second.error!.message).toContain("already ervenytelenitve");
  });

  it("seals the record of an érvénytelenítés", async () => {
    const { client, documentId } = await iktatottDocument();

    await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "Eredeti indoklás, ami nem írható át.",
    });

    // Neither rewriting the reason nor lifting the withdrawal is possible.
    for (const patch of [
      { ervenytelenites_indoka: "Átírt indok" },
      { ervenytelenitve_at: null },
      { ervenytelenitette: null },
    ]) {
      const { error } = await client.from("document").update(patch).eq("id", documentId);
      expect(error, JSON.stringify(patch)).not.toBeNull();
      expect(error!.message).toContain("cannot be changed");
    }

    const { data } = await client
      .from("document")
      .select("ervenytelenites_indoka")
      .eq("id", documentId)
      .single();
    expect(data!.ervenytelenites_indoka).toContain("Eredeti indoklás");
  });

  it("refuses to érvénytelenít something that was never iktatva", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const documentId = await insertReviewableDocument(client, companyId, "Nem iktatott");

    const { error } = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: "Nem is kellene menjen.",
    });
    expect(error).not.toBeNull();
    expect(error!.message).toContain("only an iktatott irat");
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
