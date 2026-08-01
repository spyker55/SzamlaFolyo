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
import { EmptyState } from "@/components/ui/page";
import { IconWallet } from "@/components/ui/icons";

// The urgency is carried by a dot and the heading, not by tinting the whole
// card: five stacked coloured panels read as an alarm, and most of them are
// simply "this is due next month".
const BUCKET_STYLE: Record<string, { dot: string; head: string }> = {
  lejart: { dot: "bg-red-500", head: "text-red-700" },
  ma: { dot: "bg-amber-500", head: "text-amber-700" },
  het: { dot: "bg-amber-400", head: "text-slate-900" },
  honap: { dot: "bg-blue-400", head: "text-slate-900" },
  kesobb: { dot: "bg-slate-300", head: "text-slate-900" },
  nincs_hatarido: { dot: "bg-slate-300", head: "text-slate-900" },
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
    <div className="space-y-4">
      <div className="card card-pad flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flabel">Nyitott tartozás összesen</div>
          {schedule.count === 0 ? (
            <div className="text-2xl font-semibold text-slate-400">—</div>
          ) : (
            <Totals
              totals={schedule.totals}
              className="text-2xl font-semibold tabular-nums text-slate-900"
            />
          )}
        </div>
        <span className="note">{schedule.count} tétel</span>
      </div>

      {ellenorzesreVar > 0 && (
        <p className="alert alert-warn text-xs">
          {ellenorzesreVar} irat még ellenőrzésre vár, ezek nincsenek benne az összesítésben.
          Az itt látható összeg csak az iktatott iratokat tartalmazza.
        </p>
      )}

      {error && (
        <div className="alert alert-error" role="alert">
          {error}
        </div>
      )}

      {schedule.groups.length === 0 && (
        <div className="card">
          <EmptyState icon={<IconWallet className="h-8 w-8" />}>
            Nincs nyitott fizetnivaló.
          </EmptyState>
        </div>
      )}

      {schedule.groups.map((group) => {
        const style = BUCKET_STYLE[group.bucket] ?? {
          dot: "bg-slate-300",
          head: "text-slate-900",
        };
        return (
          <div key={group.bucket} className="card overflow-hidden">
            <div className="card-head">
              <div className="flex flex-wrap items-baseline gap-3">
                <h2 className={`flex items-center gap-2 text-sm font-semibold ${style.head}`}>
                  <span className={`h-2 w-2 rounded-full ${style.dot}`} />
                  {BUCKET_LABEL[group.bucket]}
                </h2>
                <span className="note">{group.entries.length} tétel</span>
              </div>
              <Totals totals={group.totals} className="text-sm font-medium tabular-nums" />
            </div>

            <div className="table-scroll">
              <table className="tbl">
                <thead className="thead">
                  <tr>
                    <th className="th">Iktatószám</th>
                    <th className="th">Partner</th>
                    <th className="th">Típus</th>
                    <th className="th">Tárgy</th>
                    <th className="th">Határidő</th>
                    <th className="th text-right">Összeg</th>
                    <th className="th" />
                  </tr>
                </thead>
                <tbody>
                  {group.entries.map((entry) => (
                    <tr key={entry.id} className="trow">
                      <td className="td whitespace-nowrap font-medium tabular-nums text-slate-900">
                        {entry.iktatoszam ?? "—"}
                      </td>
                      <td className="td">{entry.partnerName ?? "—"}</td>
                      <td className="td whitespace-nowrap">{docKindLabel(entry.docKind)}</td>
                      <td className="td max-w-xs truncate" title={entry.targy ?? ""}>
                        {entry.targy ?? "—"}
                      </td>
                      <td className="td whitespace-nowrap">
                        <div className="tabular-nums">{entry.dueDate ?? "—"}</div>
                        <div
                          className={`text-xs ${
                            entry.daysLeft !== null && entry.daysLeft < 0
                              ? "font-medium text-red-600"
                              : "text-slate-400"
                          }`}
                        >
                          {deadlineText(entry)}
                        </div>
                      </td>
                      <td className="td whitespace-nowrap text-right font-medium tabular-nums text-slate-900">
                        {formatAmountHu(entry.grossAmount)} {entry.currency}
                      </td>
                      <td className="td whitespace-nowrap text-right">
                        <button
                          type="button"
                          onClick={() => markPaid(entry)}
                          disabled={busyId === entry.id}
                          className="btn btn-secondary btn-sm"
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
        );
      })}
    </div>
  );
}
