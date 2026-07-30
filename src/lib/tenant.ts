import { redirect } from "next/navigation";
import { createSupabaseServerClient } from "@/lib/supabase/server";

export type Membership = {
  userId: string;
  email: string;
  companyId: string;
  companyName: string;
  role: string;
};

// Resolves the signed-in user's company or redirects to the right step.
export async function requireMembership(): Promise<Membership> {
  const supabase = await createSupabaseServerClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) {
    redirect("/bejelentkezes");
  }

  const { data: member } = await supabase
    .from("company_member")
    .select("company_id, role, company:company_id (name)")
    .limit(1)
    .maybeSingle();

  if (!member) {
    redirect("/onboarding");
  }

  const company = member.company as unknown as { name: string } | null;

  return {
    userId: user.id,
    email: user.email ?? "",
    companyId: member.company_id,
    companyName: company?.name ?? "",
    role: member.role,
  };
}
