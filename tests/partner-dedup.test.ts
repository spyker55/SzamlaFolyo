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
