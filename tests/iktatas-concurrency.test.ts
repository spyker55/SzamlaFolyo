import { describe, expect, it } from "vitest";
import {
  ensureCompany,
  insertReviewableDocument,
  hasLiveCredentials,
  signedInClient,
  USER_A_EMAIL,
} from "./helpers";

// Acceptance criterion: 50 documents iktatva in parallel produce foszams with
// no gap and no collision. The baseline is read before the run so the test is
// correct regardless of how many documents were iktatva before.

const PARALLEL = 50;
const ALSZAM_PARALLEL = 20;

describe.skipIf(!hasLiveCredentials)("gapless foszam allocation (live project)", () => {
  it("allocates 50 consecutive foszams under full parallel load", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const year = new Date().getFullYear();

    const { data: baselineRow } = await client
      .from("iktatokonyv")
      .select("next_foszam")
      .eq("company_id", companyId)
      .eq("ev", year)
      .maybeSingle();
    const baseline = baselineRow?.next_foszam ?? 1;

    const documentIds = await Promise.all(
      Array.from({ length: PARALLEL }, (_, i) =>
        insertReviewableDocument(client, companyId, `Konkurencia teszt #${i + 1}`)
      )
    );

    // All 50 iktatás calls at once — this is the race the row lock must win.
    const results = await Promise.all(
      documentIds.map((id) =>
        client.rpc("iktat_document", {
          p_document_id: id,
          p_values: { targy: "Konkurencia teszt" },
        })
      )
    );

    const failures = results.filter((r) => r.error);
    expect(
      failures.map((f) => f.error?.message),
      "every parallel iktatás must succeed"
    ).toEqual([]);

    const allocated = results
      .map((r) => (r.data as { foszam: number }).foszam)
      .sort((a, b) => a - b);

    const expected = Array.from({ length: PARALLEL }, (_, i) => baseline + i);

    // No collision: all distinct. No gap: exactly baseline..baseline+49.
    expect(new Set(allocated).size).toBe(PARALLEL);
    expect(allocated).toEqual(expected);

    const { data: after } = await client
      .from("iktatokonyv")
      .select("next_foszam")
      .eq("company_id", companyId)
      .eq("ev", year)
      .single();
    expect(after!.next_foszam).toBe(baseline + PARALLEL);

    // The iktatoszam strings are unique and follow {prefix}/{foszam}-{alszam}/{ev}.
    const iktatoszams = results.map(
      (r) => (r.data as { iktatoszam: string }).iktatoszam
    );
    expect(new Set(iktatoszams).size).toBe(PARALLEL);
    for (const szam of iktatoszams) {
      expect(szam).toMatch(new RegExp(`^IKT/\\d+-1/${year}$`));
    }
  });

  it("rolls back cleanly: a failed iktatás leaves no gap behind", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const year = new Date().getFullYear();

    const { data: before } = await client
      .from("iktatokonyv")
      .select("next_foszam")
      .eq("company_id", companyId)
      .eq("ev", year)
      .single();

    // direction/doc_kind missing → the RPC raises after field application,
    // long before allocation; but even a failure later in the transaction
    // must roll the counter back. Force a mid-transaction failure with an
    // invalid enum value instead.
    const docId = await insertReviewableDocument(client, companyId, "Rollback teszt");
    const { error } = await client.rpc("iktat_document", {
      p_document_id: docId,
      p_values: { direction: "nem_letezo_irany" },
    });
    expect(error).not.toBeNull();

    const { data: after } = await client
      .from("iktatokonyv")
      .select("next_foszam")
      .eq("company_id", companyId)
      .eq("ev", year)
      .single();

    expect(after!.next_foszam).toBe(before!.next_foszam);

    // The document can still be iktatva afterwards.
    const { data, error: retryErr } = await client.rpc("iktat_document", {
      p_document_id: docId,
      p_values: {},
    });
    expect(retryErr).toBeNull();
    expect((data as { foszam: number }).foszam).toBe(before!.next_foszam);
  });

  it("refuses to iktat the same document twice", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");

    const docId = await insertReviewableDocument(client, companyId, "Dupla iktatás teszt");

    const first = await client.rpc("iktat_document", {
      p_document_id: docId,
      p_values: {},
    });
    expect(first.error).toBeNull();

    const second = await client.rpc("iktat_document", {
      p_document_id: docId,
      p_values: {},
    });
    expect(second.error).not.toBeNull();
    expect(second.error!.message).toContain("not reviewable");
  });
});

// Filing under an existing ügy allocates an alszám instead of a főszám. The
// guarantee is the same and so is the mechanism — a row lock, here on the ügy
// rather than on the iktatókönyv — so it earns the same test.
describe.skipIf(!hasLiveCredentials)("gapless alszam allocation (live project)", () => {
  it("allocates consecutive alszams under parallel load into one ugy", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const year = new Date().getFullYear();

    const firstId = await insertReviewableDocument(client, companyId, "Alszám teszt - főirat");
    const { data: opened, error: openErr } = await client.rpc("iktat_document", {
      p_document_id: firstId,
      p_values: {},
    });
    expect(openErr).toBeNull();

    const { ugy_id: ugyId, foszam } = opened as { ugy_id: string; foszam: number };
    expect((opened as { alszam: number }).alszam).toBe(1);

    const documentIds = await Promise.all(
      Array.from({ length: ALSZAM_PARALLEL }, (_, i) =>
        insertReviewableDocument(client, companyId, `Alszám teszt #${i + 2}`)
      )
    );

    const results = await Promise.all(
      documentIds.map((id) =>
        client.rpc("iktat_document", {
          p_document_id: id,
          p_values: {},
          p_ugy_id: ugyId,
        })
      )
    );

    expect(
      results.filter((r) => r.error).map((f) => f.error?.message),
      "every parallel alszám must succeed"
    ).toEqual([]);

    const allocated = results
      .map((r) => (r.data as { alszam: number }).alszam)
      .sort((a, b) => a - b);

    // The főirat took alszám 1, so these are exactly 2..21 — no gap, no collision.
    expect(new Set(allocated).size).toBe(ALSZAM_PARALLEL);
    expect(allocated).toEqual(Array.from({ length: ALSZAM_PARALLEL }, (_, i) => i + 2));

    // Every irat stayed under the one főszám, and no new one was burned.
    for (const r of results) {
      const d = r.data as { foszam: number; alszam: number; iktatoszam: string };
      expect(d.foszam).toBe(foszam);
      expect(d.iktatoszam).toBe(`IKT/${foszam}-${d.alszam}/${year}`);
    }
  });

  it("refuses to file under a lezárt ügy", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");

    const firstId = await insertReviewableDocument(client, companyId, "Lezárt ügy - főirat");
    const { data: opened } = await client.rpc("iktat_document", {
      p_document_id: firstId,
      p_values: {},
    });
    const ugyId = (opened as { ugy_id: string }).ugy_id;

    const { error: closeErr } = await client
      .from("ugy")
      .update({ status: "lezart" })
      .eq("id", ugyId);
    expect(closeErr).toBeNull();

    const secondId = await insertReviewableDocument(client, companyId, "Lezárt ügy - alirat");
    const { error } = await client.rpc("iktat_document", {
      p_document_id: secondId,
      p_values: {},
      p_ugy_id: ugyId,
    });

    expect(error).not.toBeNull();
    expect(error!.message).toContain("no further irat can be filed");
  });
});
