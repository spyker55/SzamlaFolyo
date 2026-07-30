import { createClient } from "@supabase/supabase-js";

// Service-role client: bypasses RLS. Server-only (worker, cron, upload);
// every query made with it must be explicitly scoped by company_id.
export function createSupabaseAdminClient() {
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!key) {
    throw new Error("SUPABASE_SERVICE_ROLE_KEY is not set");
  }
  return createClient(process.env.NEXT_PUBLIC_SUPABASE_URL!, key, {
    auth: { persistSession: false, autoRefreshToken: false },
  });
}
