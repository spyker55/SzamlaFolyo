import { createClient, type SupabaseClient } from "@supabase/supabase-js";

// The tests run against a real Supabase project through the public API:
// anon key + password sign-in, so RLS and the RPCs are exercised exactly the
// way the app exercises them. No service-role shortcuts.

const SUPABASE_URL = process.env.NEXT_PUBLIC_SUPABASE_URL;
const SUPABASE_ANON_KEY = process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY;

// These suites need a real project. Reading the flag instead of throwing on
// import lets the offline suites run anywhere, while the live ones report
// themselves as skipped rather than silently passing.
export const hasLiveCredentials = Boolean(SUPABASE_URL && SUPABASE_ANON_KEY);

export const TEST_PASSWORD = process.env.TEST_USER_PASSWORD ?? "SzamlafolyoTeszt1!";
export const USER_A_EMAIL = process.env.TEST_USER_A_EMAIL ?? "teszt.a@szamlafolyo-test.hu";
export const USER_B_EMAIL = process.env.TEST_USER_B_EMAIL ?? "teszt.b@szamlafolyo-test.hu";

export function anonClient(): SupabaseClient {
  if (!SUPABASE_URL || !SUPABASE_ANON_KEY) {
    throw new Error(
      "NEXT_PUBLIC_SUPABASE_URL / NEXT_PUBLIC_SUPABASE_ANON_KEY is not set. " +
        "Copy .env.test.example to .env.test and fill it in."
    );
  }
  return createClient(SUPABASE_URL, SUPABASE_ANON_KEY, {
    auth: { persistSession: false, autoRefreshToken: false },
  });
}

export async function signedInClient(email: string): Promise<SupabaseClient> {
  const client = anonClient();
  const { error } = await client.auth.signInWithPassword({
    email,
    password: TEST_PASSWORD,
  });
  if (error) {
    throw new Error(`sign-in failed for ${email}: ${error.message}`);
  }
  return client;
}

// Each test user owns one company (milestone rule); create it on first use.
export async function ensureCompany(
  client: SupabaseClient,
  name: string
): Promise<string> {
  const { data: member } = await client
    .from("company_member")
    .select("company_id")
    .limit(1)
    .maybeSingle();

  if (member) return member.company_id as string;

  const { data, error } = await client.rpc("create_company_with_owner", {
    p_name: name,
    p_tax_number: null,
  });
  if (error) throw new Error(`create_company_with_owner failed: ${error.message}`);
  return data as string;
}

// Insert a draft document ready for review (the state iktat_document expects).
export async function insertReviewableDocument(
  client: SupabaseClient,
  companyId: string,
  targy: string
): Promise<string> {
  const { data, error } = await client
    .from("document")
    .insert({
      company_id: companyId,
      processing_status: "needs_review",
      direction: "bejovo",
      doc_kind: "szamla",
      targy,
      source: "test",
    })
    .select("id")
    .single();

  if (error) throw new Error(`document insert failed: ${error.message}`);
  return data.id as string;
}
