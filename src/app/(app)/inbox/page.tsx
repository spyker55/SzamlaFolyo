import { requireMembership } from "@/lib/tenant";
import { InboxClient } from "@/components/inbox/InboxClient";

export default async function InboxPage() {
  await requireMembership();

  return (
    <div>
      <h1 className="text-xl font-semibold">Beérkező</h1>
      <p className="mt-1 text-sm text-gray-500">
        Húzd ide a PDF-et vagy képet — az AI kiolvassa, neked csak ellenőrizned kell.
      </p>
      <InboxClient />
    </div>
  );
}
