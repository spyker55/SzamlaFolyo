import { describe, expect, it } from "vitest";
import {
  ensureCompany,
  hasLiveCredentials,
  insertReviewableDocument,
  signedInClient,
  USER_A_EMAIL,
} from "./helpers";

// Partner resolution used to look only at tax_number and INSERT otherwise, so
// every supplier without a Hungarian tax number collected a new partner row per
// iktatás. These run against the real RPC, because the whole rule lives in
// app.resolve_partner() and a mock would only test the mock.

// Unique per run: these tests iktat real documents into the project they point
// at, and a fixed name would match the partner left behind by the last run.
function uniqueSuffix(): string {
  return `${Date.now()}${Math.floor(Math.random() * 1000)}`;
}

async function iktatWithPartner(
  client: Awaited<ReturnType<typeof signedInClient>>,
  companyId: string,
  targy: string,
  partner: { partner_name?: string; partner_tax_number?: string }
): Promise<string> {
  const documentId = await insertReviewableDocument(client, companyId, targy);
  const { error } = await client.rpc("iktat_document", {
    p_document_id: documentId,
    p_values: { targy, ...partner },
  });
  expect(error, `iktatás failed for ${targy}`).toBeNull();
  return documentId;
}

async function partnerIdOf(
  client: Awaited<ReturnType<typeof signedInClient>>,
  documentId: string
): Promise<string> {
  const { data } = await client
    .from("document")
    .select("partner_id")
    .eq("id", documentId)
    .single();
  expect(data!.partner_id).not.toBeNull();
  return data!.partner_id as string;
}

describe.skipIf(!hasLiveCredentials)("partner resolution (live project)", () => {
  it("keeps one partner for a supplier written two different ways", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const stem = `Dedup Teszt ${uniqueSuffix()}`;

    // The real case: a Slovak supplier with no Hungarian tax number, spelled
    // once with spaces and once without.
    const first = await iktatWithPartner(client, companyId, "Dedup 1", {
      partner_name: `${stem} s. r. o.`,
    });
    const second = await iktatWithPartner(client, companyId, "Dedup 2", {
      partner_name: `${stem.toUpperCase()} S.R.O.`,
    });

    expect(await partnerIdOf(client, first)).toBe(await partnerIdOf(client, second));
  });

  it("folds accents and punctuation, not the legal form", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const stem = `Ékezet Próba ${uniqueSuffix()}`;

    const withAccents = await iktatWithPartner(client, companyId, "Ékezet 1", {
      partner_name: `${stem} Kft.`,
    });
    const withoutAccents = await iktatWithPartner(client, companyId, "Ékezet 2", {
      partner_name: `${stem.replace(/É/g, "E").replace(/ó/g, "o")} KFT`,
    });
    expect(await partnerIdOf(client, withAccents)).toBe(
      await partnerIdOf(client, withoutAccents)
    );

    // A Kft. and a Bt. of the same name are different legal entities and must
    // never be merged — this is where the database rule is stricter than the
    // ügy suggestion's.
    const bt = await iktatWithPartner(client, companyId, "Ékezet 3", {
      partner_name: `${stem} Bt.`,
    });
    expect(await partnerIdOf(client, bt)).not.toBe(await partnerIdOf(client, withAccents));
  });

  it("learns the tax number instead of forking the partner", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const stem = `Adószám Teszt ${uniqueSuffix()}`;
    const taxNumber = `${String(Date.now()).slice(-8)}-2-42`;

    // First irat carries no tax number, the second one does.
    const withoutTax = await iktatWithPartner(client, companyId, "Adószám 1", {
      partner_name: `${stem} Kft.`,
    });
    const withTax = await iktatWithPartner(client, companyId, "Adószám 2", {
      partner_name: `${stem} Kft.`,
      partner_tax_number: taxNumber,
    });

    const partnerId = await partnerIdOf(client, withoutTax);
    expect(await partnerIdOf(client, withTax)).toBe(partnerId);

    const { data: partner } = await client
      .from("partner")
      .select("tax_number")
      .eq("id", partnerId)
      .single();
    expect(partner!.tax_number).toBe(taxNumber);
  });

  it("matches on the tax number even when the name is written differently", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const taxNumber = `${String(Date.now() + 1).slice(-8)}-2-42`;

    const first = await iktatWithPartner(client, companyId, "Adószám-egyezés 1", {
      partner_name: `Név A ${uniqueSuffix()} Kft.`,
      partner_tax_number: taxNumber,
    });
    const second = await iktatWithPartner(client, companyId, "Adószám-egyezés 2", {
      partner_name: `Teljesen Más Írásmód ${uniqueSuffix()} Kft.`,
      partner_tax_number: taxNumber,
    });

    expect(await partnerIdOf(client, first)).toBe(await partnerIdOf(client, second));
  });
});

// The partner screen offers merging as a button, so the whole safety of the
// feature is that merge_partner() moves the iratok in the same transaction and
// that unmerge_partner() can put back exactly what was moved. None of that can
// be checked without a database — the guard is a trigger and two RPCs.
describe.skipIf(!hasLiveCredentials)("partner merge (live project)", () => {
  async function twoPartners(client: Awaited<ReturnType<typeof signedInClient>>) {
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const suffix = uniqueSuffix();

    const keptDoc = await iktatWithPartner(client, companyId, `Marad ${suffix}`, {
      partner_name: `Marado Partner ${suffix} Kft.`,
    });
    const mergedDoc = await iktatWithPartner(client, companyId, `Beolvad ${suffix}`, {
      partner_name: `Beolvado Partner ${suffix} Kft.`,
    });

    return {
      companyId,
      survivorId: await partnerIdOf(client, keptDoc),
      loserId: await partnerIdOf(client, mergedDoc),
      mergedDoc,
    };
  }

  it("moves the iratok, retires the loser, and can be undone exactly", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const { survivorId, loserId, mergedDoc } = await twoPartners(client);

    const merged = await client.rpc("merge_partner", {
      p_survivor_id: survivorId,
      p_loser_id: loserId,
    });
    expect(merged.error).toBeNull();
    const mergeId = (merged.data as { merge_id: string }).merge_id;
    expect((merged.data as { document_count: number }).document_count).toBeGreaterThan(0);

    expect(await partnerIdOf(client, mergedDoc)).toBe(survivorId);

    const { data: loser } = await client
      .from("partner")
      .select("deleted_at, merged_into_partner_id")
      .eq("id", loserId)
      .single();
    expect(loser!.deleted_at).not.toBeNull();
    expect(loser!.merged_into_partner_id).toBe(survivorId);

    // History, not deletion: the row is still there and still says what it was.
    const undone = await client.rpc("unmerge_partner", { p_merge_id: mergeId });
    expect(undone.error).toBeNull();

    expect(await partnerIdOf(client, mergedDoc)).toBe(loserId);

    const { data: restored } = await client
      .from("partner")
      .select("deleted_at, merged_into_partner_id")
      .eq("id", loserId)
      .single();
    expect(restored!.deleted_at).toBeNull();
    expect(restored!.merged_into_partner_id).toBeNull();

    const second = await client.rpc("unmerge_partner", { p_merge_id: mergeId });
    expect(second.error).not.toBeNull();
    expect(second.error!.message).toContain("already undone");
  });

  it("refuses to merge two different adószám", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const stamp = String(Date.now()).slice(-7);

    const a = await iktatWithPartner(client, companyId, `Adószám A ${stamp}`, {
      partner_name: `Egyik Ceg ${stamp} Kft.`,
      partner_tax_number: `1${stamp}-2-42`,
    });
    const b = await iktatWithPartner(client, companyId, `Adószám B ${stamp}`, {
      partner_name: `Masik Ceg ${stamp} Kft.`,
      partner_tax_number: `2${stamp}-2-42`,
    });

    const { error } = await client.rpc("merge_partner", {
      p_survivor_id: await partnerIdOf(client, a),
      p_loser_id: await partnerIdOf(client, b),
    });

    expect(error).not.toBeNull();
    expect(error!.message).toContain("different tax numbers");
  });

  it("freezes a merged-away partner until the merge is undone", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const { survivorId, loserId } = await twoPartners(client);

    const merged = await client.rpc("merge_partner", {
      p_survivor_id: survivorId,
      p_loser_id: loserId,
    });
    expect(merged.error).toBeNull();

    const edit = await client.from("partner").update({ name: "Átírva" }).eq("id", loserId);
    expect(edit.error).not.toBeNull();
    expect(edit.error!.message).toContain("undo the merge");

    // And the column itself is not something a plain UPDATE may touch.
    const byHand = await client
      .from("partner")
      .update({ merged_into_partner_id: loserId })
      .eq("id", survivorId);
    expect(byHand.error).not.toBeNull();
    expect(byHand.error!.message).toContain("merge_partner()");

    await client.rpc("unmerge_partner", {
      p_merge_id: (merged.data as { merge_id: string }).merge_id,
    });
  });

  it("refuses to move a partner to another company", async () => {
    const client = await signedInClient(USER_A_EMAIL);
    const companyId = await ensureCompany(client, "Teszt Kft. A");
    const doc = await iktatWithPartner(client, companyId, `Céghatár ${uniqueSuffix()}`, {
      partner_name: `Ceghatar Partner ${uniqueSuffix()} Kft.`,
    });
    const partnerId = await partnerIdOf(client, doc);

    const { error } = await client
      .from("partner")
      .update({ company_id: "00000000-0000-0000-0000-000000000001" })
      .eq("id", partnerId);

    expect(error).not.toBeNull();
  });
});
