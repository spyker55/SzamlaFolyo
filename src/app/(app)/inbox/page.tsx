import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { InboxClient } from "@/components/inbox/InboxClient";
import { inboxAddress } from "@/lib/email/address";
import { PageHeader } from "@/components/ui/page";
import { IconMail } from "@/components/ui/icons";

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
      <PageHeader
        title="Beérkező"
        description="Húzd ide a PDF-et vagy képet — az AI kiolvassa, neked csak ellenőrizned kell."
      />

      {address && (
        <div className="card card-pad mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
          <IconMail className="h-4 w-4 shrink-0 text-blue-600" />
          <span className="text-slate-600">Vagy küldd e-mailben ide:</span>
          <code className="rounded-md bg-slate-100 px-2 py-1 font-mono text-[13px] text-slate-800">
            {address}
          </code>
          <span className="note w-full lg:w-auto lg:flex-1">
            A mellékletek automatikusan ide kerülnek. Ez a cím a cégedhez tartozik, csak
            olyannak add meg, akitől iratot vársz.
          </span>
        </div>
      )}

      <InboxClient />
    </div>
  );
}
