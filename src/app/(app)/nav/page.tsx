import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { PageHeader } from "@/components/ui/page";
import { NavKapcsolat, type KapcsolatAllapot } from "@/components/nav/NavKapcsolat";
import { NavLekerdezes } from "@/components/nav/NavLekerdezes";
import { EgyeztetesEredmeny } from "@/components/nav/EgyeztetesEredmeny";
import { loadCredentialRow, loadNavSide, loadRegisterSide } from "@/lib/nav/sync";
import { budapestToday, egyeztet } from "@/lib/nav/reconcile";
import type { NavDirection } from "@/lib/nav/query";

function param(value: string | string[] | undefined): string {
  return typeof value === "string" ? value : "";
}

// The previous month plus the current one: long enough that the reporting
// delay has settled on most of it, short enough to stay inside two of NAV's
// 35-day windows.
function defaultRange(today: string): { from: string; to: string } {
  const year = Number(today.slice(0, 4));
  const month = Number(today.slice(5, 7));
  const prevYear = month === 1 ? year - 1 : year;
  const prevMonth = month === 1 ? 12 : month - 1;
  return { from: `${prevYear}-${String(prevMonth).padStart(2, "0")}-01`, to: today };
}

function isDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value);
}

type SyncRow = {
  direction: string;
  status: string;
  date_from: string;
  date_to: string;
  invoice_count: number;
  finished_at: string | null;
  started_at: string;
  error: string | null;
};

export default async function NavPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { role } = await requireMembership();
  const sp = await searchParams;
  const today = budapestToday();
  const fallback = defaultRange(today);

  const direction: NavDirection = param(sp.irany) === "kimeno" ? "kimeno" : "bejovo";
  const fromRaw = param(sp.tol);
  const toRaw = param(sp.ig);
  const from = isDate(fromRaw) ? fromRaw : fallback.from;
  const to = isDate(toRaw) ? toRaw : fallback.to;

  const canEdit = role === "owner" || role === "admin";
  const supabase = await createSupabaseServerClient();

  const [credential, navSide, registerSide, { data: syncData }] = await Promise.all([
    canEdit ? loadCredentialRow(supabase) : Promise.resolve(null),
    loadNavSide(supabase, { direction, from, to }),
    loadRegisterSide(supabase, { direction, from, to }),
    supabase
      .from("nav_sync")
      .select("direction, status, date_from, date_to, invoice_count, finished_at, started_at, error")
      .eq("direction", direction)
      .order("started_at", { ascending: false })
      .limit(1),
  ]);

  const lastSync = ((syncData ?? []) as unknown as SyncRow[])[0] ?? null;
  const eredmeny = egyeztet({ nav: navSide, register: registerSide, today });

  const allapot: KapcsolatAllapot = {
    beallitva: credential !== null,
    taxNumber: credential?.tax_number ?? null,
    login: credential?.login ?? null,
    environment: credential?.environment ?? "production",
    lastOkAt: credential?.last_ok_at ?? null,
    lastError: credential?.last_error ?? null,
  };

  return (
    <div className="space-y-5">
      <PageHeader
        lead="Rendszer"
        title="NAV Online Számla"
        description={
          <>
            Összeveti, mit tud a NAV, és mi van meg az iktatóban. Az alkalmazás{" "}
            <strong>csak lekérdez</strong>: adatszolgáltatást nem küld be, mert azt annak a
            programnak a dolga, amelyik a számlát kiállította.
          </>
        }
      />

      <NavKapcsolat allapot={allapot} canEdit={canEdit} />

      <div className="card card-pad space-y-4">
        <form className="flex flex-wrap items-end gap-3" method="get">
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Irány</span>
            <select name="irany" defaultValue={direction} className="control w-48">
              <option value="bejovo">Bejövő (nekünk állították ki)</option>
              <option value="kimeno">Kimenő (mi állítottuk ki)</option>
            </select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Kelt ettől</span>
            <input type="date" name="tol" defaultValue={from} className="control w-44" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">eddig</span>
            <input type="date" name="ig" defaultValue={to} className="control w-44" />
          </label>
          <button type="submit" className="btn btn-secondary">
            Időszak megjelenítése
          </button>
        </form>

        <div className="flex flex-wrap items-end justify-between gap-4 border-t border-slate-100 pt-4">
          <div className="text-sm text-slate-500">
            {lastSync ? (
              <>
                Utolsó lekérdezés: {(lastSync.finished_at ?? lastSync.started_at).slice(0, 16).replace("T", " ")} ·{" "}
                {lastSync.date_from} – {lastSync.date_to} ·{" "}
                {lastSync.status === "hiba" ? (
                  <span className="text-red-700">hibával állt le</span>
                ) : lastSync.status === "fut" ? (
                  "fut"
                ) : (
                  `${lastSync.invoice_count} számla`
                )}
              </>
            ) : (
              "Ebben az irányban még nem futott lekérdezés. Az alábbi lista addig üres."
            )}
          </div>
          {canEdit ? (
            <NavLekerdezes
              direction={direction}
              from={from}
              to={to}
              disabled={credential === null}
            />
          ) : (
            <p className="text-sm text-slate-400">
              Lekérdezést tulajdonos vagy adminisztrátor indíthat.
            </p>
          )}
        </div>
      </div>

      {navSide.length === 0 && registerSide.length === 0 ? (
        <p className="alert alert-muted">
          Ebben az időszakban sem a NAV-nál, sem az iktatóban nincs számla ebben az irányban.
        </p>
      ) : (
        <EgyeztetesEredmeny eredmeny={eredmeny} direction={direction} />
      )}

      <p className="text-xs text-slate-400">
        A lista csak arról tud, amit a legutóbbi lekérdezés lehozott: a NAV-tól kapott sorok
        itt tárolódnak, és soha nem törlődnek. A rendszer semmit nem javít ki magától — az
        eltérés jelzés, nem művelet.
      </p>
    </div>
  );
}
