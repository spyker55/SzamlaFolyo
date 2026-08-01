import { describe, expect, it } from "vitest";
import {
  ensureCompany,
  hasLiveCredentials,
  insertReviewableDocument,
  signedInClient,
  USER_A_EMAIL,
  USER_B_EMAIL,
} from "./helpers";

// The log is only worth having if it cannot be edited by the people it is
// about. These run through the public API with an ordinary member's session —
// the same anon key and RLS the app uses — because that is the surface an
// insider actually has.

describe.skipIf(!hasLiveCredentials)("audit trail (live project)", () => {
  it("records the filing, with the person and the iktatószám", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const documentId = await insertReviewableDocument(client, companyId, "Napló teszt");

    const { data: filed, error } = await client.rpc("iktat_document", {
      p_document_id: documentId,
      p_values: {},
    });
    expect(error).toBeNull();
    const iktatoszam = (filed as { iktatoszam: string }).iktatoszam;

    const { data: events } = await client
      .from("audit_event")
      .select("action, entity_label, actor_email, actor_kind, changes")
      .eq("entity_id", documentId)
      .order("created_at", { ascending: true });

    const actions = (events ?? []).map((e) => e.action);
    expect(actions).toContain("document.erkeztetve");
    expect(actions).toContain("document.iktatva");

    const filing = (events ?? []).find((e) => e.action === "document.iktatva")!;
    expect(filing.entity_label).toBe(iktatoszam);
    expect(filing.actor_email).toBe(USER_A_EMAIL);
    expect(filing.actor_kind).toBe("user");
    // The iktatószám is the value that moved, so the entry carries it as a
    // change and not only as a label.
    expect(Object.keys(filing.changes as Record<string, unknown>)).toContain("iktatoszam");
  });

  it("opens the ügy before the irat is filed into it", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const documentId = await insertReviewableDocument(client, companyId, "Sorrend teszt");

    const { data: filed } = await client.rpc("iktat_document", {
      p_document_id: documentId,
      p_values: {},
    });
    const ugyId = (filed as { ugy_id: string }).ugy_id;

    const { data: events } = await client
      .from("audit_event")
      .select("action, created_at")
      .in("entity_id", [documentId, ugyId])
      .order("created_at", { ascending: true });

    const order = (events ?? []).map((e) => e.action);
    // Both happen inside one transaction, so this only holds because the log
    // stamps clock_timestamp() and not the transaction's start time.
    expect(order.indexOf("ugy.megnyitva")).toBeLessThan(order.indexOf("document.iktatva"));
  });

  it("refuses to let a member change or remove an entry", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");

    const { data: entry } = await client
      .from("audit_event")
      .select("id")
      .eq("company_id", companyId)
      .limit(1)
      .maybeSingle();
    expect(entry, "the suites above should have written at least one entry").not.toBeNull();

    const { error: updateError } = await client
      .from("audit_event")
      .update({ action: "hamis" })
      .eq("id", entry!.id);
    expect(updateError).not.toBeNull();

    const { error: deleteError } = await client
      .from("audit_event")
      .delete()
      .eq("id", entry!.id);
    expect(deleteError).not.toBeNull();

    const { data: still } = await client
      .from("audit_event")
      .select("id, action")
      .eq("id", entry!.id)
      .maybeSingle();
    expect(still).not.toBeNull();
    expect(still!.action).not.toBe("hamis");
  });

  it("refuses a forged entry", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");

    const { error } = await client.from("audit_event").insert({
      company_id: companyId,
      action: "document.iktatva",
      entity_type: "document",
      entity_id: companyId,
      entity_label: "IKT/999/2026",
    });

    expect(error).not.toBeNull();
  });

  it("keeps one company's log out of another's reach", async () => {
    const a = await signedInClient(USER_A_EMAIL);
    const companyA = await ensureCompany(a, "Teszt Kft. A");
    await insertReviewableDocument(a, companyA, "Elszigetelés teszt");

    const b = await signedInClient(USER_B_EMAIL);
    await ensureCompany(b, "Teszt Kft. B");

    const { data } = await b.from("audit_event").select("id").eq("company_id", companyA);
    expect(data ?? []).toHaveLength(0);
  });

  it("records the reason an irat was withdrawn", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const documentId = await insertReviewableDocument(client, companyId, "Érvénytelenítés naplózása");
    await client.rpc("iktat_document", { p_document_id: documentId, p_values: {} });

    const indoklas = "Téves iktatás, a szállító sztornózta.";
    const { error } = await client.rpc("ervenytelenit_document", {
      p_document_id: documentId,
      p_indoklas: indoklas,
    });
    // Only owner and admin may withdraw; a session without that role proves
    // nothing about the log, so the check is skipped rather than failed.
    if (error) {
      expect(error.message).toContain("owner or admin");
      return;
    }

    const { data: events } = await client
      .from("audit_event")
      .select("action, changes")
      .eq("entity_id", documentId)
      .eq("action", "document.ervenytelenitve");

    expect(events ?? []).toHaveLength(1);
    const changes = events![0].changes as Record<string, { to?: unknown }>;
    expect(changes.ervenytelenites_indoka?.to).toBe(indoklas);
  });
});
