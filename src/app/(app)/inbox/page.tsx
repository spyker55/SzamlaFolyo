import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { InboxClient } from "@/components/inbox/InboxClient";
import { inboxAddress } from "@/lib/email/address";

export default async function InboxPage() {
  const membership = await requireMembership();
  const supabase = await createSupabaseServerClient();

  const { data: company } = await supabase
    .from("company")
    .select("inbox_token")
    .eq("id", membership.companyId)
    .maybeSingle();

  const address = company?.inbox_token ? inboxAddress(company.inbox_token) : null;

  return (
    <div>
      <h1 className="text-xl font-semibold">Beérkező</h1>
      <p className="mt-1 text-sm text-gray-500">
        Húzd ide a PDF-et vagy képet — az AI kiolvassa, neked csak ellenőrizned kell.
      </p>

      {address && (
        <p className="mt-2 text-sm text-gray-500">
          Vagy küldd e-mailben ide:{" "}
          <code className="rounded bg-gray-100 px-1.5 py-0.5 text-gray-800">{address}</code>{" "}
          <span className="text-gray-400">
            — a mellékletek automatikusan ide kerülnek. Ez a cím a cégedhez tartozik, csak
            olyannak add meg, akitől iratot vársz.
          </span>
        </p>
      )}

      <InboxClient />
    </div>
  );
}
