import Link from "next/link";
import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { PageHeader, EmptyState } from "@/components/ui/page";
import { AuditList } from "@/components/audit/AuditList";
import { NaploFilters } from "@/components/audit/NaploFilters";
import { auditFilterPrefix } from "@/lib/audit/labels";
import { fetchAuditEvents, periodSince } from "@/lib/audit/query";
import { IconHistory } from "@/components/ui/icons";

// A month is the period somebody actually asks about ("mi történt a múlt
// héten?"); anything longer is a search, and the filters above the list are
// the search.
const DEFAULT_PERIOD = "30";
const PAGE_SIZE = 100;

function param(value: string | string[] | undefined): string {
  return typeof value === "string" ? value : "";
}

export default async function NaploPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  await requireMembership();
  const sp = await searchParams;

  const tipus = param(sp.tipus);
  const ki = param(sp.ki);
  const idoszak = param(sp.idoszak) || DEFAULT_PERIOD;
  const page = Math.max(1, Number(param(sp.oldal)) || 1);

  const supabase = await createSupabaseServerClient();

  // Neither query depends on the other's answer.
  const [events, { data: memberData }] = await Promise.all([
    fetchAuditEvents(supabase, {
      actionPrefix: auditFilterPrefix(tipus),
      actorUserId: ki || null,
      since: periodSince(idoszak),
      // One past the page, so "Továbbiak" appears only when there is more.
      limit: PAGE_SIZE + 1,
      offset: (page - 1) * PAGE_SIZE,
    }),
    supabase.from("company_member").select("user_id, app_user:user_id (full_name, email)").limit(100),
  ]);

  const members = ((memberData ?? []) as unknown as {
    user_id: string;
    app_user: { full_name: string | null; email: string } | null;
  }[]).map((m) => ({
    id: m.user_id,
    name: m.app_user?.full_name ?? m.app_user?.email ?? m.user_id,
  }));

  const hasMore = events.length > PAGE_SIZE;
  const shown = hasMore ? events.slice(0, PAGE_SIZE) : events;
  const filtering = Boolean(tipus || ki) || idoszak !== DEFAULT_PERIOD;

  const pageHref = (n: number) => {
    const next = new URLSearchParams({ tipus, ki, idoszak });
    for (const [k, v] of [...next.entries()]) if (!v) next.delete(k);
    if (n > 1) next.set("oldal", String(n));
    const query = next.toString();
    return query ? `/naplo?${query}` : "/naplo";
  };

  return (
    <div>
      <PageHeader
        title="Napló"
        description="Minden változás, amit valaki vagy valami az iratokon, az ügyeken és a partnereken végzett. A bejegyzések nem módosíthatók és nem törölhetők — az adatbázis utasítja vissza."
      />

      <NaploFilters
        tipus={tipus}
        ki={ki}
        idoszak={idoszak}
        members={members}
        filtering={filtering}
      />

      <div className="card">
        <div className="card-head">
          <h2 className="card-title">
            {shown.length} bejegyzés
            {page > 1 && <span className="font-normal text-slate-400"> · {page}. oldal</span>}
          </h2>
        </div>

        <div className="px-4 pb-2">
          {shown.length === 0 ? (
            filtering ? (
              <EmptyState
                icon={<IconHistory className="h-8 w-8" />}
                hint="Próbálj hosszabb időszakot, vagy töröld a szűrőket."
              >
                Nincs a szűrésnek megfelelő bejegyzés.
              </EmptyState>
            ) : (
              <EmptyState
                icon={<IconHistory className="h-8 w-8" />}
                hint="A napló attól a naptól rögzít, amikor bekapcsoltuk. A korábbi műveletekről nincs bejegyzés — visszamenőleg kitalálni őket pont az lenne, amit egy naplónak sosem szabad."
              >
                Még nincs naplóbejegyzés.
              </EmptyState>
            )
          ) : (
            <AuditList events={shown} />
          )}
        </div>

        {(hasMore || page > 1) && (
          <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
            {page > 1 ? (
              <Link href={pageHref(page - 1)} className="btn btn-secondary">
                Előző
              </Link>
            ) : (
              <span />
            )}
            {hasMore && (
              <Link href={pageHref(page + 1)} className="btn btn-secondary">
                Továbbiak
              </Link>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
