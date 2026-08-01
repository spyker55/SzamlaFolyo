"use client";

import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { DOC_KIND_OPTIONS, docKindLabel } from "@/lib/domain/doc-kind";
import { ervenytelenit } from "@/lib/iktatas/actions";
import { EmptyState } from "@/components/ui/page";
import { IconBook, IconSearch } from "@/components/ui/icons";

export type IktatokonyvRow = {
  id: string;
  iktatoszam: string;
  eloado: string;
  irattariJel: string;
  erkezett: string;
  bekuldo: string;
  iratSzama: string;
  mellekletDb: number;
  targy: string;
  kezelesiFeljegyzes: string;
  hatarido: string;
  irattarbaHelyezve: string;
  ervenytelen: boolean;
  ervenytelenitesIndoka: string;
  ervenytelenitveAt: string;
  direction: string;
  docKind: string;
};

const DIRECTION_LABEL: Record<string, string> = {
  bejovo: "Bejövő",
  kimeno: "Kimenő",
  belso: "Belső",
};

// The classic Excel columns:
// Sorszám · Előadó · Irattári jel · Érkezett · Beküldő · Irat száma ·
// Mellékletek db · Tárgy · Kezelési feljegyzések · Határidő · Irattárba helyezés
//
// Típus is the one addition, placed next to the sender and the document's own
// number because it qualifies the same thing those two identify.
export function Iktatokonyv({
  rows,
  canErvenytelenit,
}: {
  rows: IktatokonyvRow[];
  canErvenytelenit: boolean;
}) {
  const router = useRouter();
  const [query, setQuery] = useState("");
  const [direction, setDirection] = useState("");
  const [docKind, setDocKind] = useState("");

  // Érvénytelenítés is irreversible and needs a written reason, so it gets a
  // dialog rather than a confirm() the user can click through.
  const [target, setTarget] = useState<IktatokonyvRow | null>(null);
  const [reason, setReason] = useState("");
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const closeDialog = () => {
    setTarget(null);
    setReason("");
    setDialogError(null);
  };

  const submitErvenytelenites = () => {
    if (!target || pending) return;
    setDialogError(null);
    startTransition(async () => {
      const result = await ervenytelenit(target.id, reason);
      if (!result.ok) {
        setDialogError(result.error);
        return;
      }
      closeDialog();
      router.refresh();
    });
  };

  const filtering = query.trim() !== "" || direction !== "" || docKind !== "";

  const clearFilters = () => {
    setQuery("");
    setDirection("");
    setDocKind("");
  };

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return rows.filter((r) => {
      if (direction && r.direction !== direction) return false;
      if (docKind && r.docKind !== docKind) return false;
      if (!q) return true;
      return [r.iktatoszam, r.bekuldo, r.iratSzama, r.targy, r.eloado, r.kezelesiFeljegyzes]
        .join(" ")
        .toLowerCase()
        .includes(q);
    });
  }, [rows, query, direction, docKind]);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <div className="relative w-full sm:w-72">
          <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Keresés: iktatószám, beküldő, tárgy…"
            className="control pl-9"
          />
        </div>
        <select
          value={direction}
          onChange={(e) => setDirection(e.target.value)}
          className="control w-auto"
        >
          <option value="">Minden irány</option>
          {Object.entries(DIRECTION_LABEL).map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </select>
        <select
          value={docKind}
          onChange={(e) => setDocKind(e.target.value)}
          className="control w-auto"
        >
          <option value="">Minden fajta</option>
          {DOC_KIND_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        {filtering && (
          <button type="button" onClick={clearFilters} className="btn btn-ghost btn-sm">
            Szűrők törlése
          </button>
        )}
        <span className="note ml-auto self-center">
          {filtering ? `${filtered.length} / ${rows.length} tétel` : `${rows.length} tétel`}
        </span>
      </div>

      <div className="card table-scroll">
        <table className="tbl whitespace-nowrap">
          <thead className="thead">
            <tr>
              <th className="th px-3">Sorszám</th>
              <th className="th px-3">Előadó</th>
              <th className="th px-3">Irattári jel</th>
              <th className="th px-3">Érkezett</th>
              <th className="th px-3">Beküldő neve</th>
              <th className="th px-3">Típus</th>
              <th className="th px-3">Irat száma</th>
              <th className="th px-3">Mell. db</th>
              <th className="th px-3">Tárgy</th>
              <th className="th px-3">Kezelési feljegyzések</th>
              <th className="th px-3">Határidő</th>
              <th className="th px-3">Irattárba helyezés</th>
              <th className="th px-3" />
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 && (
              <tr>
                <td colSpan={13}>
                  {/* It used to say "Nincs iktatott irat." whatever the reason,
                      so a search that matched nothing looked like an empty
                      register. */}
                  {rows.length === 0 ? (
                    <EmptyState
                      icon={<IconBook className="h-8 w-8" />}
                      hint="Ide az kerül, amit a Beérkezőben ellenőriztél és iktattál."
                    >
                      Még nincs iktatott irat.
                    </EmptyState>
                  ) : (
                    <EmptyState
                      icon={<IconSearch className="h-8 w-8" />}
                      hint={`A ${rows.length} iktatott iratból egy sem felel meg a szűrésnek.`}
                    >
                      Nincs találat.
                      <button type="button" onClick={clearFilters} className="link ml-2">
                        Szűrők törlése
                      </button>
                    </EmptyState>
                  )}
                </td>
              </tr>
            )}
            {filtered.map((r) => (
              <tr
                key={r.id}
                className={`trow ${r.ervenytelen ? "text-slate-400 line-through" : ""}`}
              >
                <td className="td px-3 font-medium tabular-nums text-slate-900">
                  {r.iktatoszam}
                </td>
                <td className="td px-3">{r.eloado}</td>
                <td className="td px-3">{r.irattariJel}</td>
                <td className="td px-3 tabular-nums">{r.erkezett}</td>
                <td className="td px-3">{r.bekuldo}</td>
                <td className="td px-3">{docKindLabel(r.docKind)}</td>
                <td className="td px-3 tabular-nums">{r.iratSzama}</td>
                <td className="td px-3 text-center tabular-nums">{r.mellekletDb}</td>
                <td className="td max-w-xs truncate px-3" title={r.targy}>
                  {r.targy}
                </td>
                <td className="td max-w-xs truncate px-3" title={r.kezelesiFeljegyzes}>
                  {r.kezelesiFeljegyzes}
                </td>
                <td className="td px-3 tabular-nums">{r.hatarido}</td>
                <td className="td px-3 tabular-nums">{r.irattarbaHelyezve}</td>
                <td className="td px-3 text-right">
                  {r.ervenytelen ? (
                    <span
                      className="badge badge-red"
                      title={
                        r.ervenytelenitesIndoka
                          ? `Érvénytelenítve${r.ervenytelenitveAt ? ` (${r.ervenytelenitveAt})` : ""}: ${r.ervenytelenitesIndoka}`
                          : "Érvénytelenítve"
                      }
                    >
                      érvénytelen
                    </span>
                  ) : (
                    canErvenytelenit && (
                      <button
                        type="button"
                        onClick={() => setTarget(r)}
                        className="btn btn-ghost btn-sm hover:text-red-600"
                        title="Érvénytelenítés — az iktatószám megmarad, a művelet nem vonható vissza"
                      >
                        Érvénytelenít
                      </button>
                    )
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {target && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="ervenytelenites-cim"
          onKeyDown={(e) => {
            if (e.key === "Escape") closeDialog();
          }}
        >
          <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-pop">
            <h2 id="ervenytelenites-cim" className="text-base font-semibold text-slate-900">
              Irat érvénytelenítése
            </h2>
            <p className="mt-1 text-sm text-slate-600">
              <span className="font-medium">{target.iktatoszam}</span>
              {target.targy ? ` — ${target.targy}` : ""}
            </p>
            <p className="alert alert-warn mt-3 text-xs">
              Az iktatószám megmarad és nem osztható ki újra, az irat pedig áthúzva marad az
              iktatókönyvben. Az érvénytelenítés <strong>nem vonható vissza</strong>.
            </p>

            <label htmlFor="ervenytelenites-indok" className="flabel mt-4">
              Az érvénytelenítés indoka
            </label>
            <textarea
              id="ervenytelenites-indok"
              autoFocus
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Pl. Téves iktatás, az irat a másik céghez tartozik."
              className="control"
            />

            {dialogError && (
              <p className="alert alert-error mt-2" role="alert">
                {dialogError}
              </p>
            )}

            <div className="mt-4 flex justify-end gap-2">
              <button
                type="button"
                onClick={closeDialog}
                disabled={pending}
                className="btn btn-secondary"
              >
                Mégse
              </button>
              <button
                type="button"
                onClick={submitErvenytelenites}
                disabled={pending || reason.trim().length < 5}
                className="btn btn-danger"
              >
                {pending ? "Érvénytelenítés…" : "Érvénytelenítés"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
