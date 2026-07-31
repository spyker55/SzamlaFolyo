"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { deadlineState, deadlineText } from "@/lib/ugy/order";
import { UGY_STATUS_LABEL, type UgyStatus } from "@/lib/ugy/status";

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
  folyamatban: "bg-blue-100 text-blue-800",
  felfuggesztve: "bg-amber-100 text-amber-800",
  lezart: "bg-gray-200 text-gray-700",
  irattarazott: "bg-gray-100 text-gray-500",
};

const DEADLINE_STYLE: Record<string, string> = {
  lejart: "text-red-600 font-medium",
  ma: "text-amber-700 font-medium",
  kozeli: "text-amber-700",
  tavoli: "text-gray-400",
  nincs: "text-gray-400",
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
    <div className="mt-4 space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-wrap gap-1">
          {FILTERS.map((f) => (
            <button
              key={f.value}
              type="button"
              onClick={() => setStatus(f.value)}
              disabled={pending}
              className={`rounded-md px-3 py-1 text-sm ${
                status === f.value
                  ? "bg-blue-700 text-white"
                  : "border border-gray-300 text-gray-700 hover:bg-gray-50"
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Keresés tárgyra, partnerre, iktatószámra"
          className="ml-auto w-72 rounded-md border border-gray-300 px-3 py-1 text-sm"
        />
      </div>

      {shown.length === 0 ? (
        <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-gray-400">
          {ugyek.length === 0 ? "Ebben az állapotban nincs ügy." : "Nincs találat."}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
              <tr>
                <th className="px-4 py-2">Ügy</th>
                <th className="px-4 py-2">Tárgy</th>
                <th className="px-4 py-2">Partner</th>
                <th className="px-4 py-2">Állapot</th>
                <th className="px-4 py-2">Határidő</th>
                <th className="px-4 py-2 text-right">Iratok</th>
                <th className="px-4 py-2 text-right">Összeg</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((u) => (
                <tr key={u.id} className="border-b border-gray-100 last:border-0 hover:bg-gray-50">
                  <td className="whitespace-nowrap px-4 py-2 font-medium">
                    <Link href={`/ugyek/${u.id}`} className="text-blue-700 hover:underline">
                      {u.iktatoszam}
                    </Link>
                  </td>
                  <td className="max-w-md px-4 py-2">
                    <Link href={`/ugyek/${u.id}`} className="block truncate" title={u.targy}>
                      {u.targy || "—"}
                    </Link>
                  </td>
                  <td className="max-w-[14rem] truncate px-4 py-2">{u.partnerName || "—"}</td>
                  <td className="whitespace-nowrap px-4 py-2">
                    <span
                      className={`rounded-full px-2 py-0.5 text-xs ${STATUS_STYLE[u.status] ?? "bg-gray-100 text-gray-600"}`}
                    >
                      {UGY_STATUS_LABEL[u.status as UgyStatus] ?? u.status}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-4 py-2">
                    <div>{u.hatarido ?? "—"}</div>
                    <div className={`text-xs ${DEADLINE_STYLE[deadlineState(u.hatarido, today)]}`}>
                      {deadlineText(u.hatarido, today)}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-4 py-2 text-right">{u.iratCount}</td>
                  <td className="whitespace-nowrap px-4 py-2 text-right">
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

      <p className="text-xs text-gray-500">
        {shown.length} ügy. Az összeg csak az iktatott iratokat tartalmazza; az
        érvénytelenítettek az iratszámban benne vannak, az összegben nem.
      </p>
    </div>
  );
}
