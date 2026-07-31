"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { docKindLabel } from "@/lib/domain/doc-kind";
import { jeloldKifizetve } from "@/lib/fizetes/actions";
import {
  BUCKET_LABEL,
  type CurrencyTotal,
  type Schedule,
  type ScheduleEntry,
} from "@/lib/fizetes/schedule";

const BUCKET_STYLE: Record<string, string> = {
  lejart: "border-red-300 bg-red-50",
  ma: "border-amber-300 bg-amber-50",
  het: "border-amber-200 bg-white",
  honap: "border-gray-200 bg-white",
  kesobb: "border-gray-200 bg-white",
  nincs_hatarido: "border-gray-200 bg-white",
};

function Totals({ totals, className = "" }: { totals: CurrencyTotal[]; className?: string }) {
  if (totals.length === 0) return null;
  return (
    <span className={className}>
      {totals.map((t, i) => (
        <span key={t.currency}>
          {i > 0 ? " · " : ""}
          {formatAmountHu(t.amount)} {t.currency}
        </span>
      ))}
    </span>
  );
}

function deadlineText(entry: ScheduleEntry): string {
  if (entry.daysLeft === null) return "—";
  if (entry.daysLeft < 0) return `${-entry.daysLeft} napja lejárt`;
  if (entry.daysLeft === 0) return "ma esedékes";
  return `${entry.daysLeft} nap múlva`;
}

export function FizetesekClient({
  schedule,
  today,
  ellenorzesreVar,
}: {
  schedule: Schedule;
  today: string;
  ellenorzesreVar: number;
}) {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [, startTransition] = useTransition();

  const markPaid = (entry: ScheduleEntry) => {
    setError(null);
    setBusyId(entry.id);
    startTransition(async () => {
      // Settled today unless the user edits it afterwards on the irat.
      const result = await jeloldKifizetve(entry.id, today);
      setBusyId(null);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      router.refresh();
    });
  };

  return (
    <div className="mt-4 space-y-4">
      <div className="flex flex-wrap items-baseline gap-3 rounded-lg border border-gray-200 bg-white p-4">
        <span className="text-sm text-gray-500">Nyitott tartozás összesen</span>
        <Totals totals={schedule.totals} className="text-lg font-semibold" />
        {schedule.count === 0 && <span className="text-lg font-semibold">—</span>}
        <span className="text-xs text-gray-400">
          {schedule.count} tétel
        </span>
      </div>

      {ellenorzesreVar > 0 && (
        <p className="rounded-md bg-amber-50 p-2 text-xs text-amber-900">
          {ellenorzesreVar} irat még ellenőrzésre vár, ezek nincsenek benne az összesítésben.
          Az itt látható összeg csak az iktatott iratokat tartalmazza.
        </p>
      )}

      {error && (
        <div className="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">
          {error}
        </div>
      )}

      {schedule.groups.length === 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-gray-400">
          Nincs nyitott fizetnivaló.
        </div>
      )}

      {schedule.groups.map((group) => (
        <div
          key={group.bucket}
          className={`overflow-hidden rounded-lg border ${BUCKET_STYLE[group.bucket] ?? "border-gray-200 bg-white"}`}
        >
          <div className="flex flex-wrap items-baseline gap-3 border-b border-gray-200 px-4 py-2">
            <h2 className="text-sm font-semibold">{BUCKET_LABEL[group.bucket]}</h2>
            <span className="text-xs text-gray-500">{group.entries.length} tétel</span>
            <Totals totals={group.totals} className="ml-auto text-sm font-medium" />
          </div>

          <div className="overflow-x-auto bg-white">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                  <th className="px-4 py-2">Iktatószám</th>
                  <th className="px-4 py-2">Partner</th>
                  <th className="px-4 py-2">Típus</th>
                  <th className="px-4 py-2">Tárgy</th>
                  <th className="px-4 py-2">Határidő</th>
                  <th className="px-4 py-2 text-right">Összeg</th>
                  <th className="px-4 py-2" />
                </tr>
              </thead>
              <tbody>
                {group.entries.map((entry) => (
                  <tr key={entry.id} className="border-b border-gray-100 last:border-0">
                    <td className="whitespace-nowrap px-4 py-2 font-medium">
                      {entry.iktatoszam ?? "—"}
                    </td>
                    <td className="px-4 py-2">{entry.partnerName ?? "—"}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-gray-600">
                      {docKindLabel(entry.docKind)}
                    </td>
                    <td className="max-w-xs truncate px-4 py-2" title={entry.targy ?? ""}>
                      {entry.targy ?? "—"}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2">
                      <div>{entry.dueDate ?? "—"}</div>
                      <div
                        className={`text-xs ${entry.daysLeft !== null && entry.daysLeft < 0 ? "text-red-600" : "text-gray-400"}`}
                      >
                        {deadlineText(entry)}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-right font-medium">
                      {formatAmountHu(entry.grossAmount)} {entry.currency}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-right">
                      <button
                        type="button"
                        onClick={() => markPaid(entry)}
                        disabled={busyId === entry.id}
                        className="rounded-md border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                      >
                        Kifizetve
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ))}
    </div>
  );
}
