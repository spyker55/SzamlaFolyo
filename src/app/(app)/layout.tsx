import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { signOut } from "@/lib/auth/actions";
import { MobileLogo, MobileNav, Sidebar } from "@/components/app/Nav";
import { IconLogout } from "@/components/ui/icons";

// The company's own day. The server may be anywhere; the register is in
// Budapest, and erkezett_at already defaults to that clock.
function budapestDate(): string {
  return new Intl.DateTimeFormat("hu-HU", {
    timeZone: "Europe/Budapest",
    year: "numeric",
    month: "long",
    day: "numeric",
    weekday: "long",
  }).format(new Date());
}

function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return "?";
  return words
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "")
    .join("");
}

export default async function AppLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const membership = await requireMembership();
  const supabase = await createSupabaseServerClient();

  // What is actually waiting for a human — not everything in the Beérkező.
  // A document still being read by the model is not a task yet.
  const { count } = await supabase
    .from("document")
    .select("id", { count: "exact", head: true })
    .in("processing_status", ["needs_review", "extraction_failed"])
    .is("deleted_at", null);

  const inboxCount = count ?? 0;

  return (
    <div className="flex min-h-screen bg-canvas">
      <Sidebar inboxCount={inboxCount} />

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
          <div className="flex h-14 items-center gap-3 px-4 sm:px-6">
            <MobileLogo />
            <span className="hidden text-sm text-slate-500 md:inline">
              {budapestDate()}
            </span>

            <div className="ml-auto flex items-center gap-3">
              <div className="hidden text-right sm:block">
                <div className="text-sm font-medium text-slate-800">
                  {membership.companyName}
                </div>
                <div className="text-[11px] text-slate-400">{membership.email}</div>
              </div>
              <span
                className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-xs font-semibold text-blue-700"
                title={membership.companyName}
              >
                {initials(membership.companyName)}
              </span>
              <form action={signOut}>
                <button type="submit" className="btn btn-ghost btn-sm" title="Kijelentkezés">
                  <IconLogout className="h-4 w-4" />
                  <span className="sr-only">Kijelentkezés</span>
                </button>
              </form>
            </div>
          </div>

          <MobileNav inboxCount={inboxCount} />
        </header>

        <main className="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6">
          {children}
        </main>
      </div>
    </div>
  );
}
