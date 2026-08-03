"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { docKindLabel } from "@/lib/domain/doc-kind";
import { mentMetaadat, valtoztatStatus } from "@/lib/ugy/actions";
import { deadlineState, deadlineText } from "@/lib/ugy/order";
import {
  acceptsNewIrat,
  isEditable,
  isRunning,
  nextStatuses,
  transitionLabel,
  UGY_STATUS_LABEL,
  type UgyStatus,
} from "@/lib/ugy/status";
import { PAYABLE_KINDS } from "@/lib/fizetes/schedule";
import {
  budapestEv,
  irattariJel as irattariJelOf,
  megorzes,
  megorzesSzoveg,
  MEGORZES_LABEL,
  megorzesStilus,
  type IrattariTetel,
} from "@/lib/irattar/terv";
import { EmptyState } from "@/components/ui/page";
import { IconArrowLeft } from "@/components/ui/icons";

type Ugy = {
  id: string;
  iktatoszam: string;
  foszam: number;
  ev: number;
  targy: string;
  status: UgyStatus;
  hatarido: string | null;
  irattariJel: string;
  irattariTetelId: string | null;
  eloadoUserId: string | null;
  openedAt: string;
  closedAt: string | null;
  irattarbaHelyezveAt: string | null;
  partnerName: string | null;
  partnerTaxNumber: string | null;
};

type Irat = {
  id: string;
  alszam: number | null;
  iktatoszam: string | null;
  docKind: string | null;
  direction: string | null;
  targy: string | null;
  iratSzama: string | null;
  erkezettAt: string | null;
  dueDate: string | null;
  grossAmount: number | null;
  currency: string | null;
  status: string;
  fizetveAt: string | null;
  ervenytelenitesIndoka: string | null;
};

const DEADLINE_STYLE: Record<string, string> = {
  lejart: "text-red-600 font-medium",
  ma: "text-amber-700 font-medium",
  kozeli: "text-amber-700",
  tavoli: "text-slate-500",
  nincs: "text-slate-400",
  lezarult: "text-slate-400",
};

const STATUS_STYLE: Record<string, string> = {
  folyamatban: "badge-blue",
  felfuggesztve: "badge-amber",
  lezart: "badge-slate",
  irattarazott: "badge-slate",
};

export function UgyDetail({
  ugy,
  iratok,
  members,
  tetelek,
  today,
  mostEv,
}: {
  ugy: Ugy;
  iratok: Irat[];
  members: { id: string; name: string }[];
  tetelek: IrattariTetel[];
  today: string;
  // The current year in Budapest, computed on the server: the retention line
  // must not read differently because a browser's clock is in another zone.
  mostEv: number;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);

  const [targy, setTargy] = useState(ugy.targy);
  const [hatarido, setHatarido] = useState(ugy.hatarido ?? "");
  const [irattariTetelId, setIrattariTetelId] = useState(ugy.irattariTetelId ?? "");
  const [eloado, setEloado] = useState(ugy.eloadoUserId ?? "");

  const editable = isEditable(ugy.status);

  const move = (to: UgyStatus) => {
    setError(null);
    setBusy(true);
    startTransition(async () => {
      const result = await valtoztatStatus(ugy.id, ugy.status, to);
      setBusy(false);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      router.refresh();
    });
  };

  const save = () => {
    setError(null);
    setBusy(true);
    startTransition(async () => {
      const result = await mentMetaadat(ugy.id, {
        targy,
        hatarido: hatarido === "" ? null : hatarido,
        irattariTetelId: irattariTetelId === "" ? null : irattariTetelId,
        eloadoUserId: eloado === "" ? null : eloado,
      });
      setBusy(false);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      setEditing(false);
      router.refresh();
    });
  };

  const totals = new Map<string, number>();
  for (const i of iratok) {
    if (i.status !== "iktatva" || i.grossAmount === null) continue;
    const c = i.currency ?? "—";
    totals.set(c, (totals.get(c) ?? 0) + i.grossAmount);
  }

  // The same test the fizetési naptár applies, so the two screens cannot
  // disagree about what is still owed: an incoming, filed, unsettled bill.
  const unpaid = iratok.filter(
    (i) =>
      i.status === "iktatva" &&
      i.direction === "bejovo" &&
      !i.fizetveAt &&
      PAYABLE_KINDS.includes(i.docKind ?? "")
  );

  const tetel = tetelek.find((t) => t.id === ugy.irattariTetelId) ?? null;
  // Retention is counted from the closing, and only a closed ügy has one.
  const megorzesAllapot = megorzes(
    budapestEv(ugy.closedAt),
    tetel?.orzesiIdoEv ?? null,
    mostEv,
    tetel !== null
  );

  return (
    <div className="space-y-4">
      <div>
        <Link href="/ugyek" className="link inline-flex items-center gap-1 text-sm">
          <IconArrowLeft className="h-4 w-4" />
          Ügyek
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight tabular-nums text-slate-900">
            {ugy.iktatoszam}
          </h1>
          <span className={`badge ${STATUS_STYLE[ugy.status] ?? "badge-slate"}`}>
            {UGY_STATUS_LABEL[ugy.status]}
          </span>
        </div>
      </div>

      {error && (
        <div className="alert alert-error" role="alert">
          {error}
        </div>
      )}

      {!acceptsNewIrat(ugy.status) && (
        <p className="alert alert-muted text-xs">
          Ebbe az ügybe {ugy.status === "lezart" ? "lezárt" : "irattárazott"} állapotban nem
          lehet több iratot iktatni. A meglévő iratok és az iktatószámuk változatlanok.
        </p>
      )}

      {/* A closed ügy's deadline stops mattering, but an unpaid invoice does
          not: the money is owed whether or not the case is filed away. Saying
          so here is also the answer to "why is this still in the fizetési
          naptár?" — which is exactly where it belongs. */}
      {!isRunning(ugy.status) && unpaid.length > 0 && (
        <p className="alert alert-warn text-sm">
          Az ügy {UGY_STATUS_LABEL[ugy.status].toLowerCase()}, de {unpaid.length} tétele még
          nincs kifizetve. A{" "}
          <Link href="/fizetesek" className="link">
            fizetési naptárban
          </Link>{" "}
          továbbra is szerepel.
        </p>
      )}

      <div className="card card-pad">
        {editing ? (
          <div className="space-y-4">
            <label className="block">
              <span className="flabel">Tárgy</span>
              <input
                value={targy}
                onChange={(e) => setTargy(e.target.value)}
                className="control"
              />
            </label>
            <div className="flex flex-wrap gap-4">
              <label>
                <span className="flabel">Határidő</span>
                <input
                  type="date"
                  value={hatarido}
                  onChange={(e) => setHatarido(e.target.value)}
                  className="control w-auto"
                />
              </label>
              <label>
                <span className="flabel">Irattári tétel</span>
                <select
                  value={irattariTetelId}
                  onChange={(e) => setIrattariTetelId(e.target.value)}
                  className="control w-auto"
                >
                  <option value="">— nincs besorolva —</option>
                  {tetelek.map((t) => (
                    <option key={t.id} value={t.id}>
                      {irattariJelOf(t.tetelszam, t.orzesiIdoEv)} · {t.nev}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <span className="flabel">Előadó</span>
                <select
                  value={eloado}
                  onChange={(e) => setEloado(e.target.value)}
                  className="control w-auto"
                >
                  <option value="">—</option>
                  {members.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={save}
                disabled={busy}
                className="btn btn-primary"
              >
                Mentés
              </button>
              <button
                type="button"
                onClick={() => {
                  setEditing(false);
                  setTargy(ugy.targy);
                  setHatarido(ugy.hatarido ?? "");
                  setIrattariTetelId(ugy.irattariTetelId ?? "");
                  setEloado(ugy.eloadoUserId ?? "");
                }}
                className="btn btn-secondary"
              >
                Mégse
              </button>
            </div>
          </div>
        ) : (
          <>
            <div className="flex items-start gap-4">
              <div className="min-w-0 flex-1">
                <div className="text-base font-medium text-slate-900">{ugy.targy || "—"}</div>
                <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                  <Field label="Partner">
                    {ugy.partnerName ?? "—"}
                    {ugy.partnerTaxNumber && (
                      <span className="block text-xs tabular-nums text-slate-400">
                        {ugy.partnerTaxNumber}
                      </span>
                    )}
                  </Field>
                  <Field label="Határidő">
                    {ugy.hatarido ?? "—"}
                    {deadlineText(ugy.hatarido, today, ugy.status) && (
                      <span
                        className={`block text-xs ${
                          DEADLINE_STYLE[deadlineState(ugy.hatarido, today, ugy.status)]
                        }`}
                      >
                        {deadlineText(ugy.hatarido, today, ugy.status)}
                      </span>
                    )}
                  </Field>
                  <Field label="Előadó">
                    {members.find((m) => m.id === ugy.eloadoUserId)?.name ?? "—"}
                  </Field>
                  <Field label="Irattári jel">
                    {ugy.irattariJel || "—"}
                    {tetel && (
                      <span className="block max-w-xs text-xs text-slate-400">{tetel.nev}</span>
                    )}
                  </Field>
                  <Field label="Megnyitva">{ugy.openedAt.slice(0, 10)}</Field>
                  {ugy.closedAt && <Field label="Lezárva">{ugy.closedAt.slice(0, 10)}</Field>}
                  {ugy.irattarbaHelyezveAt && (
                    <Field label="Irattárban">{ugy.irattarbaHelyezveAt.slice(0, 10)}</Field>
                  )}
                  <Field label="Iratok">
                    {iratok.length}
                    {totals.size > 0 && (
                      <span className="block text-xs tabular-nums text-slate-500">
                        {[...totals].map(([c, a], i) => (
                          <span key={c}>
                            {i > 0 ? " · " : ""}
                            {formatAmountHu(Number(a.toFixed(4)))} {c}
                          </span>
                        ))}
                      </span>
                    )}
                  </Field>
                </dl>

                {/* The retention line is prose and not a grid cell: "2034.
                    december 31-ig őrzendő" is the answer to a question, and a
                    cell would truncate it into a fragment. */}
                <p className="mt-4 flex flex-wrap items-baseline gap-2 text-xs">
                  <span className={`badge ${megorzesStilus(megorzesAllapot.allapot)}`}>
                    {MEGORZES_LABEL[megorzesAllapot.allapot]}
                  </span>
                  <span className="text-slate-500">{megorzesSzoveg(megorzesAllapot)}</span>
                  {tetel?.jogszabaly && (
                    <span className="text-slate-400">{tetel.jogszabaly}</span>
                  )}
                </p>
              </div>
              {editable && (
                <button
                  type="button"
                  onClick={() => setEditing(true)}
                  className="btn btn-secondary"
                >
                  Szerkesztés
                </button>
              )}
            </div>

            <div className="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
              {nextStatuses(ugy.status).map((to) => (
                <button
                  key={to}
                  type="button"
                  onClick={() => move(to)}
                  disabled={busy}
                  className="btn btn-secondary"
                >
                  {transitionLabel(ugy.status, to)}
                </button>
              ))}
            </div>
          </>
        )}
      </div>

      <div className="card">
        <div className="card-head">
          <h2 className="card-title">Iratok ({iratok.length})</h2>
        </div>
        <div className="table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Iktatószám</th>
                <th className="th">Típus</th>
                <th className="th">Bizonylatszám</th>
                <th className="th">Beérkezés</th>
                <th className="th">Határidő</th>
                <th className="th text-right">Összeg</th>
                <th className="th">Állapot</th>
              </tr>
            </thead>
            <tbody>
              {iratok.length === 0 && (
                <tr>
                  <td colSpan={7}>
                    <EmptyState>Ehhez az ügyhöz még nincs irat.</EmptyState>
                  </td>
                </tr>
              )}
              {iratok.map((i) => (
                <tr key={i.id} className="trow">
                  <td className="td whitespace-nowrap font-medium tabular-nums text-slate-900">
                    {i.iktatoszam ?? "—"}
                    {i.targy && (
                      <span className="block max-w-xs truncate text-xs font-normal text-slate-500">
                        {i.targy}
                      </span>
                    )}
                  </td>
                  <td className="td whitespace-nowrap">{docKindLabel(i.docKind)}</td>
                  <td className="td whitespace-nowrap tabular-nums">{i.iratSzama ?? "—"}</td>
                  <td className="td whitespace-nowrap tabular-nums">{i.erkezettAt ?? "—"}</td>
                  <td className="td whitespace-nowrap tabular-nums">{i.dueDate ?? "—"}</td>
                  <td className="td whitespace-nowrap text-right tabular-nums">
                    {i.grossAmount === null
                      ? "—"
                      : `${formatAmountHu(i.grossAmount)} ${i.currency ?? ""}`}
                  </td>
                  <td className="td whitespace-nowrap">
                    {i.status === "ervenytelenitve" ? (
                      <span className="badge badge-red" title={i.ervenytelenitesIndoka ?? ""}>
                        Érvénytelenítve
                      </span>
                    ) : i.fizetveAt ? (
                      <span className="badge badge-green">Kifizetve {i.fizetveAt}</span>
                    ) : (
                      <span className="badge badge-slate">Iktatva</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="flabel">{label}</dt>
      <dd className="text-slate-800">{children}</dd>
    </div>
  );
}
