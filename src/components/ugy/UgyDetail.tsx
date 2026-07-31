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
  nextStatuses,
  transitionLabel,
  UGY_STATUS_LABEL,
  type UgyStatus,
} from "@/lib/ugy/status";

type Ugy = {
  id: string;
  iktatoszam: string;
  foszam: number;
  ev: number;
  targy: string;
  status: UgyStatus;
  hatarido: string | null;
  irattariJel: string;
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
  tavoli: "text-gray-500",
  nincs: "text-gray-400",
};

export function UgyDetail({
  ugy,
  iratok,
  members,
  today,
}: {
  ugy: Ugy;
  iratok: Irat[];
  members: { id: string; name: string }[];
  today: string;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);

  const [targy, setTargy] = useState(ugy.targy);
  const [hatarido, setHatarido] = useState(ugy.hatarido ?? "");
  const [irattariJel, setIrattariJel] = useState(ugy.irattariJel);
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
        irattariJel,
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

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <Link href="/ugyek" className="text-sm text-blue-700 hover:underline">
          ← Ügyek
        </Link>
        <h1 className="text-xl font-semibold">{ugy.iktatoszam}</h1>
        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
          {UGY_STATUS_LABEL[ugy.status]}
        </span>
      </div>

      {error && (
        <div className="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">
          {error}
        </div>
      )}

      {!acceptsNewIrat(ugy.status) && (
        <p className="rounded-md bg-gray-100 p-2 text-xs text-gray-600">
          Ebbe az ügybe {ugy.status === "lezart" ? "lezárt" : "irattárazott"} állapotban nem
          lehet több iratot iktatni. A meglévő iratok és az iktatószámuk változatlanok.
        </p>
      )}

      <div className="rounded-lg border border-gray-200 bg-white p-4">
        {editing ? (
          <div className="space-y-3">
            <label className="block text-sm">
              <span className="text-xs text-gray-500">Tárgy</span>
              <input
                value={targy}
                onChange={(e) => setTargy(e.target.value)}
                className="mt-1 w-full rounded-md border border-gray-300 px-2 py-1"
              />
            </label>
            <div className="flex flex-wrap gap-4">
              <label className="text-sm">
                <span className="block text-xs text-gray-500">Határidő</span>
                <input
                  type="date"
                  value={hatarido}
                  onChange={(e) => setHatarido(e.target.value)}
                  className="mt-1 rounded-md border border-gray-300 px-2 py-1"
                />
              </label>
              <label className="text-sm">
                <span className="block text-xs text-gray-500">Irattári jel</span>
                <input
                  value={irattariJel}
                  onChange={(e) => setIrattariJel(e.target.value)}
                  className="mt-1 rounded-md border border-gray-300 px-2 py-1"
                />
              </label>
              <label className="text-sm">
                <span className="block text-xs text-gray-500">Előadó</span>
                <select
                  value={eloado}
                  onChange={(e) => setEloado(e.target.value)}
                  className="mt-1 rounded-md border border-gray-300 px-2 py-1"
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
                className="rounded-md bg-blue-700 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50"
              >
                Mentés
              </button>
              <button
                type="button"
                onClick={() => {
                  setEditing(false);
                  setTargy(ugy.targy);
                  setHatarido(ugy.hatarido ?? "");
                  setIrattariJel(ugy.irattariJel);
                  setEloado(ugy.eloadoUserId ?? "");
                }}
                className="rounded-md border border-gray-300 px-4 py-1.5 text-sm"
              >
                Mégse
              </button>
            </div>
          </div>
        ) : (
          <>
            <div className="flex items-start gap-4">
              <div className="min-w-0 flex-1">
                <div className="text-base font-medium">{ugy.targy || "—"}</div>
                <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                  <Field label="Partner">
                    {ugy.partnerName ?? "—"}
                    {ugy.partnerTaxNumber && (
                      <span className="block text-xs text-gray-400">{ugy.partnerTaxNumber}</span>
                    )}
                  </Field>
                  <Field label="Határidő">
                    {ugy.hatarido ?? "—"}
                    <span
                      className={`block text-xs ${DEADLINE_STYLE[deadlineState(ugy.hatarido, today)]}`}
                    >
                      {deadlineText(ugy.hatarido, today)}
                    </span>
                  </Field>
                  <Field label="Előadó">
                    {members.find((m) => m.id === ugy.eloadoUserId)?.name ?? "—"}
                  </Field>
                  <Field label="Irattári jel">{ugy.irattariJel || "—"}</Field>
                  <Field label="Megnyitva">{ugy.openedAt.slice(0, 10)}</Field>
                  {ugy.closedAt && <Field label="Lezárva">{ugy.closedAt.slice(0, 10)}</Field>}
                  {ugy.irattarbaHelyezveAt && (
                    <Field label="Irattárban">{ugy.irattarbaHelyezveAt.slice(0, 10)}</Field>
                  )}
                  <Field label="Iratok">
                    {iratok.length}
                    {totals.size > 0 && (
                      <span className="block text-xs text-gray-500">
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
              </div>
              {editable && (
                <button
                  type="button"
                  onClick={() => setEditing(true)}
                  className="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50"
                >
                  Szerkesztés
                </button>
              )}
            </div>

            <div className="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-3">
              {nextStatuses(ugy.status).map((to) => (
                <button
                  key={to}
                  type="button"
                  onClick={() => move(to)}
                  disabled={busy}
                  className="rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50 disabled:opacity-50"
                >
                  {transitionLabel(ugy.status, to)}
                </button>
              ))}
            </div>
          </>
        )}
      </div>

      <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
            <tr>
              <th className="px-4 py-2">Iktatószám</th>
              <th className="px-4 py-2">Típus</th>
              <th className="px-4 py-2">Bizonylatszám</th>
              <th className="px-4 py-2">Beérkezés</th>
              <th className="px-4 py-2">Határidő</th>
              <th className="px-4 py-2 text-right">Összeg</th>
              <th className="px-4 py-2">Állapot</th>
            </tr>
          </thead>
          <tbody>
            {iratok.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-gray-400">
                  Ehhez az ügyhöz még nincs irat.
                </td>
              </tr>
            )}
            {iratok.map((i) => (
              <tr key={i.id} className="border-b border-gray-100 last:border-0">
                <td className="whitespace-nowrap px-4 py-2 font-medium">
                  {i.iktatoszam ?? "—"}
                  {i.targy && (
                    <span className="block max-w-xs truncate text-xs font-normal text-gray-500">
                      {i.targy}
                    </span>
                  )}
                </td>
                <td className="whitespace-nowrap px-4 py-2">{docKindLabel(i.docKind)}</td>
                <td className="whitespace-nowrap px-4 py-2">{i.iratSzama ?? "—"}</td>
                <td className="whitespace-nowrap px-4 py-2">{i.erkezettAt ?? "—"}</td>
                <td className="whitespace-nowrap px-4 py-2">{i.dueDate ?? "—"}</td>
                <td className="whitespace-nowrap px-4 py-2 text-right">
                  {i.grossAmount === null ? "—" : `${formatAmountHu(i.grossAmount)} ${i.currency ?? ""}`}
                </td>
                <td className="whitespace-nowrap px-4 py-2 text-xs">
                  {i.status === "ervenytelenitve" ? (
                    <span className="text-red-600" title={i.ervenytelenitesIndoka ?? ""}>
                      Érvénytelenítve
                    </span>
                  ) : i.fizetveAt ? (
                    <span className="text-green-700">Kifizetve {i.fizetveAt}</span>
                  ) : (
                    <span className="text-gray-500">Iktatva</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-gray-500">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  );
}
