import { beforeAll, describe, expect, it } from "vitest";
import type { SupabaseClient } from "@supabase/supabase-js";
import {
  anonClient,
  ensureCompany,
  hasLiveCredentials,
  insertReviewableDocument,
  signedInClient,
  USER_A_EMAIL,
  USER_B_EMAIL,
} from "./helpers";

// Acceptance criterion: a user of another company must not see the first
// company's documents in any way. Every path goes through the public API.

describe.skipIf(!hasLiveCredentials)("tenant isolation (RLS) (live project)", () => {
  let clientA: SupabaseClient;
  let clientB: SupabaseClient;
  let companyA: string;
  let companyB: string;
  let docA: string;
  let ugyA: string;
  let iktatoszamA: string;

  beforeAll(async () => {
    clientA = await signedInClient(USER_A_EMAIL);
    clientB = await signedInClient(USER_B_EMAIL);
    companyA = await ensureCompany(clientA, "Teszt Kft. A");
    companyB = await ensureCompany(clientB, "Teszt Kft. B");
    expect(companyA).not.toBe(companyB);

    docA = await insertReviewableDocument(clientA, companyA, "Izolációs teszt irat");
    const { data, error } = await clientA.rpc("iktat_document", {
      p_document_id: docA,
      p_values: {
        partner_name: "Titkos Beszállító Kft.",
        partner_tax_number: "10773381-2-41",
      },
    });
    expect(error).toBeNull();
    iktatoszamA = (data as { iktatoszam: string }).iktatoszam;
    ugyA = (data as { ugy_id: string }).ugy_id;
  });

  it("B cannot file its own document under A's ugy", async () => {
    // p_ugy_id takes an arbitrary uuid from the client, so it is a tenancy
    // boundary in its own right: guessing A's ugy id must not attach B's irat
    // to A's foszam — nor reveal that the ugy exists.
    const docB = await insertReviewableDocument(clientB, companyB, "Idegen ügy teszt");
    const { error } = await clientB.rpc("iktat_document", {
      p_document_id: docB,
      p_values: {},
      p_ugy_id: ugyA,
    });

    expect(error).not.toBeNull();
    expect(error!.message).toContain("ugy not found");

    const { data: after } = await clientB
      .from("document")
      .select("ugy_id, iktatoszam, processing_status")
      .eq("id", docB)
      .single();
    expect(after!.ugy_id).toBeNull();
    expect(after!.iktatoszam).toBeNull();
  });

  it("B cannot read A's documents — by id, by list, or by iktatoszam", async () => {
    const byId = await clientB.from("document").select("*").eq("id", docA);
    expect(byId.data).toEqual([]);

    const byCompany = await clientB.from("document").select("id").eq("company_id", companyA);
    expect(byCompany.data).toEqual([]);

    const byIktatoszam = await clientB
      .from("document")
      .select("id")
      .eq("iktatoszam", iktatoszamA);
    expect(byIktatoszam.data).toEqual([]);
  });

  it("B cannot read A's ugy, iktatokonyv, partner, extraction or corrections", async () => {
    for (const table of ["ugy", "iktatokonyv", "partner", "extraction", "field_correction"]) {
      const { data } = await clientB.from(table).select("id").eq("company_id", companyA);
      expect(data, `${table} must be invisible across tenants`).toEqual([]);
    }
  });

  it("B cannot iktat A's document", async () => {
    const otherDocA = await insertReviewableDocument(clientA, companyA, "B nem iktathatja");

    const { error } = await clientB.rpc("iktat_document", {
      p_document_id: otherDocA,
      p_values: {},
    });
    expect(error).not.toBeNull();
    // The error must not reveal that the document exists.
    expect(error!.message).toContain("not found");

    // A's document is untouched.
    const { data: still } = await clientA
      .from("document")
      .select("processing_status")
      .eq("id", otherDocA)
      .single();
    expect(still!.processing_status).toBe("needs_review");
  });

  it("B cannot update A's document and cannot insert into A's company", async () => {
    // UPDATE silently affects 0 rows under RLS.
    await clientB.from("document").update({ targy: "eltérített" }).eq("id", docA);
    const { data: unchanged } = await clientA
      .from("document")
      .select("targy")
      .eq("id", docA)
      .single();
    expect(unchanged!.targy).toBe("Izolációs teszt irat");

    // INSERT into a foreign company violates the WITH CHECK policy.
    const { error: insertErr } = await clientB.from("document").insert({
      company_id: companyA,
      processing_status: "received",
      source: "test",
    });
    expect(insertErr).not.toBeNull();
  });

  it("B cannot delete A's document (no delete policy exists at all)", async () => {
    await clientB.from("document").delete().eq("id", docA);
    // Even A cannot physically delete an iktatott document.
    await clientA.from("document").delete().eq("id", docA);

    const { data: alive } = await clientA
      .from("document")
      .select("id, iktatoszam")
      .eq("id", docA)
      .single();
    expect(alive!.iktatoszam).toBe(iktatoszamA);
  });

  it("anonymous clients see nothing at all", async () => {
    const anon = anonClient();
    for (const table of ["document", "company", "ugy", "partner", "iktatokonyv"]) {
      const { data } = await anon.from(table).select("id").limit(1);
      expect(data ?? [], `${table} must be empty for anon`).toEqual([]);
    }

    const { error } = await anon.rpc("iktat_document", {
      p_document_id: docA,
      p_values: {},
    });
    expect(error).not.toBeNull();
  });
});
