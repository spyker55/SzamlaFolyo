"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { deadlineState, deadlineText } from "@/lib/ugy/order";
import { UGY_STATUS_LABEL, type UgyStatus } from "@/lib/ugy/status";
import { EmptyState } from "@/components/ui/page";
import { IconFolder, IconSearch } from "@/components/ui/icons";

export type UgyRow = {
  id: string;
  iktatoszam: string;
  foszam: number;
  ev: number;
  targy: string;
  status: string;
  hatarido: string | null;
  irattariJel: string;
  partnerName: string;
  eloado: string;
  iratCount: number;
  totals: { currency: string; amount: number }[];
};

const FILTERS: { value: string; label: string }[] = [
  { value: "aktiv", label: "Aktív" },
  { value: "folyamatban", label: "Folyamatban" },
  { value: "felfuggesztve", label: "Felfüggesztve" },
  { value: "lezart", label: "Lezárt" },
  { value: "irattarazott", label: "Irattárazott" },
  { value: "mind", label: "Mind" },
];

const STATUS_STYLE: Record<string, string> = {
  folyamatban: "badge-blue",
  felfuggesztve: "badge-amber",
  lezart: "badge-slate",
  irattarazott: "badge-slate",
};

const DEADLINE_STYLE: Record<string, string> = {
  lejart: "text-red-600 font-medium",
  ma: "text-amber-700 font-medium",
  kozeli: "text-amber-700",
  tavoli: "text-slate-400",
  nincs: "text-slate-400",
  lezarult: "text-slate-400",
};

export function UgyekClient({
  ugyek,
  status,
  today,
}: {
  ugyek: UgyRow[];
  status: string;
  today: string;
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [search, setSearch] = useState("");

  const shown = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (q === "") return ugyek;
    return ugyek.filter(
      (u) =>
        u.targy.toLowerCase().includes(q) ||
        u.partnerName.toLowerCase().includes(q) ||
        u.iktatoszam.toLowerCase().includes(q)
    );
  }, [ugyek, search]);

  const setStatus = (value: string) => {
    startTransition(() => router.push(`/ugyek?allapot=${value}`));
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-wrap gap-1.5">
          {FILTERS.map((f) => (
            <button
              key={f.value}
              type="button"
              onClick={() => setStatus(f.value)}
              disabled={pending}
              className={`chip ${status === f.value ? "chip-on" : ""}`}
            >
              {f.label}
            </button>
          ))}
        </div>
        <div className="relative w-full sm:ml-auto sm:w-72">
          <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Keresés tárgyra, partnerre, iktatószámra"
            className="control pl-9"
          />
        </div>
      </div>

      {shown.length === 0 ? (
        <div className="card">
          {ugyek.length === 0 ? (
            <EmptyState
              icon={<IconFolder className="h-8 w-8" />}
              hint={
                status === "aktiv"
                  ? "Ügy akkor keletkezik, amikor egy iratot új főszámra iktatsz."
                  : undefined
              }
            >
              {status === "aktiv" ? (
                <>
                  Nincs nyitott ügyed.
                  <button
                    type="button"
                    onClick={() => setStatus("mind")}
                    className="link ml-2"
                  >
                    Lezártak megjelenítése
                  </button>
                </>
              ) : (
                "Ebben az állapotban nincs ügy."
              )}
            </EmptyState>
          ) : (
            <EmptyState
              icon={<IconSearch className="h-8 w-8" />}
              hint={`A(z) ${ugyek.length} listázott ügyből egy sem felel meg a keresésnek.`}
            >
              Nincs találat.
              <button type="button" onClick={() => setSearch("")} className="link ml-2">
                Keresés törlése
              </button>
            </EmptyState>
          )}
        </div>
      ) : (
        <div className="card table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Ügy</th>
                <th className="th">Tárgy</th>
                <th className="th">Partner</th>
                <th className="th">Állapot</th>
                <th className="th">Határidő</th>
                <th className="th text-right">Iratok</th>
                <th className="th text-right">Összeg</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((u) => (
                <tr key={u.id} className="trow">
                  <td className="td whitespace-nowrap font-medium tabular-nums">
                    <Link href={`/ugyek/${u.id}`} className="link">
                      {u.iktatoszam}
                    </Link>
                  </td>
                  <td className="td max-w-md">
                    <Link
                      href={`/ugyek/${u.id}`}
                      className="block truncate text-slate-800"
                      title={u.targy}
                    >
                      {u.targy || "—"}
                    </Link>
                  </td>
                  <td className="td max-w-[14rem] truncate">{u.partnerName || "—"}</td>
                  <td className="td whitespace-nowrap">
                    <span className={`badge ${STATUS_STYLE[u.status] ?? "badge-slate"}`}>
                      {UGY_STATUS_LABEL[u.status as UgyStatus] ?? u.status}
                    </span>
                  </td>
                  <td className="td whitespace-nowrap">
                    <div
                      className={`tabular-nums ${
                        deadlineState(u.hatarido, today, u.status) === "lezarult"
                          ? "text-slate-400"
                          : ""
                      }`}
                    >
                      {u.hatarido ?? "—"}
                    </div>
                    {deadlineText(u.hatarido, today, u.status) && (
                      <div
                        className={`text-xs ${
                          DEADLINE_STYLE[deadlineState(u.hatarido, today, u.status)]
                        }`}
                      >
                        {deadlineText(u.hatarido, today, u.status)}
                      </div>
                    )}
                  </td>
                  <td className="td whitespace-nowrap text-right tabular-nums">{u.iratCount}</td>
                  <td className="td whitespace-nowrap text-right tabular-nums">
                    {u.totals.length === 0
                      ? "—"
                      : u.totals.map((t, i) => (
                          <span key={t.currency}>
                            {i > 0 ? " · " : ""}
                            {formatAmountHu(t.amount)} {t.currency}
                          </span>
                        ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="note">
        {shown.length} ügy. Az összeg csak az iktatott iratokat tartalmazza; az
        érvénytelenítettek az iratszámban benne vannak, az összegben nem.
      </p>
    </div>
  );
}
