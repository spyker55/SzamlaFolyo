"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import {
  irattariJel,
  NEM_SELEJTEZHETO_JEL,
  type IrattariTetel,
} from "@/lib/irattar/terv";
import { mentTetel, ujTetel, valtoztatTetelAllapot } from "@/lib/irattar/actions";
import { IconPlus } from "@/components/ui/icons";

export type TetelRow = IrattariTetel & { ugyCount: number };

type Draft = {
  tetelszam: string;
  nev: string;
  orzesiIdo: string;
  jogszabaly: string;
  megjegyzes: string;
};

const EMPTY: Draft = { tetelszam: "", nev: "", orzesiIdo: "", jogszabaly: "", megjegyzes: "" };

function draftOf(t: TetelRow): Draft {
  return {
    tetelszam: t.tetelszam,
    nev: t.nev,
    orzesiIdo: t.orzesiIdoEv === null ? "" : String(t.orzesiIdoEv),
    jogszabaly: t.jogszabaly ?? "",
    megjegyzes: t.megjegyzes ?? "",
  };
}

// An empty field means nem selejtezhető, and that has to be impossible to
// arrive at by accident — hence the explicit checkbox in the form rather than
// "leave it blank and hope".
function orzesiIdoOf(draft: Draft): number | null {
  const raw = draft.orzesiIdo.trim();
  if (raw === "") return null;
  const n = Number(raw);
  return Number.isFinite(n) ? Math.trunc(n) : Number.NaN;
}

export function IrattariTervClient({
  tetelek,
  canEdit,
}: {
  tetelek: TetelRow[];
  canEdit: boolean;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [showRetired, setShowRetired] = useState(false);

  const rows = tetelek.filter((t) => t.aktiv || showRetired);
  const retiredCount = tetelek.filter((t) => !t.aktiv).length;

  const run = (fn: () => Promise<{ ok: true } | { ok: false; error: string }>) => {
    setError(null);
    setBusy(true);
    startTransition(async () => {
      const result = await fn();
      setBusy(false);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      setEditing(null);
      setCreating(false);
      setDraft(EMPTY);
      router.refresh();
    });
  };

  const save = () => {
    const orzesi = orzesiIdoOf(draft);
    if (Number.isNaN(orzesi)) {
      setError("Az őrzési idő csak szám lehet, vagy üres, ha nem selejtezhető.");
      return;
    }
    const common = {
      nev: draft.nev,
      orzesiIdoEv: orzesi,
      jogszabaly: draft.jogszabaly,
      megjegyzes: draft.megjegyzes,
    };
    if (creating) {
      run(() => ujTetel({ ...common, tetelszam: draft.tetelszam }));
    } else if (editing) {
      run(() => mentTetel(editing, common));
    }
  };

  return (
    <div className="space-y-4">
      {error && (
        <div className="alert alert-error" role="alert">
          {error}
        </div>
      )}

      <div className="card">
        <div className="card-head flex flex-wrap items-center justify-between gap-2">
          <h2 className="card-title">Irattári tételek ({rows.length})</h2>
          <div className="flex items-center gap-2">
            {retiredCount > 0 && (
              <button
                type="button"
                onClick={() => setShowRetired((v) => !v)}
                className="btn btn-ghost btn-sm"
              >
                {showRetired ? "Inaktívak elrejtése" : `Inaktívak (${retiredCount})`}
              </button>
            )}
            {canEdit && !creating && (
              <button
                type="button"
                onClick={() => {
                  setCreating(true);
                  setEditing(null);
                  setDraft(EMPTY);
                }}
                className="btn btn-secondary btn-sm"
              >
                <IconPlus className="h-4 w-4" />
                Új tétel
              </button>
            )}
          </div>
        </div>

        {creating && (
          <div className="border-b border-slate-100 px-4 py-4">
            <TetelForm
              draft={draft}
              setDraft={setDraft}
              withTetelszam
              busy={busy}
              onSave={save}
              onCancel={() => {
                setCreating(false);
                setDraft(EMPTY);
                setError(null);
              }}
            />
          </div>
        )}

        <div className="table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Jel</th>
                <th className="th">Megnevezés</th>
                <th className="th">Őrzési idő</th>
                <th className="th">Jogszabály</th>
                <th className="th text-right">Ügyek</th>
                {canEdit && <th className="th" />}
              </tr>
            </thead>
            <tbody>
              {rows.map((t) =>
                editing === t.id ? (
                  <tr key={t.id}>
                    <td className="td" colSpan={canEdit ? 6 : 5}>
                      <TetelForm
                        draft={draft}
                        setDraft={setDraft}
                        withTetelszam={false}
                        busy={busy}
                        onSave={save}
                        onCancel={() => {
                          setEditing(null);
                          setError(null);
                        }}
                      />
                    </td>
                  </tr>
                ) : (
                  <tr key={t.id} className={`trow ${t.aktiv ? "" : "opacity-50"}`}>
                    <td className="td whitespace-nowrap font-medium tabular-nums text-slate-900">
                      {irattariJel(t.tetelszam, t.orzesiIdoEv)}
                    </td>
                    <td className="td">
                      {t.nev}
                      {t.megjegyzes && (
                        <span className="mt-0.5 block max-w-xl text-xs text-slate-500">
                          {t.megjegyzes}
                        </span>
                      )}
                      {!t.aktiv && (
                        <span className="badge badge-slate mt-1 inline-block">Inaktív</span>
                      )}
                    </td>
                    <td className="td whitespace-nowrap">
                      {t.orzesiIdoEv === null ? (
                        <span className="badge badge-slate">Nem selejtezhető</span>
                      ) : (
                        <span className="tabular-nums">{t.orzesiIdoEv} év</span>
                      )}
                    </td>
                    <td className="td text-xs text-slate-500">{t.jogszabaly ?? "—"}</td>
                    <td className="td text-right tabular-nums">{t.ugyCount}</td>
                    {canEdit && (
                      <td className="td whitespace-nowrap text-right">
                        <button
                          type="button"
                          onClick={() => {
                            setEditing(t.id);
                            setCreating(false);
                            setDraft(draftOf(t));
                            setError(null);
                          }}
                          className="btn btn-ghost btn-sm"
                          disabled={busy}
                        >
                          Szerkesztés
                        </button>
                        <button
                          type="button"
                          onClick={() => run(() => valtoztatTetelAllapot(t.id, !t.aktiv))}
                          className="btn btn-ghost btn-sm"
                          disabled={busy || (t.aktiv && t.ugyCount > 0)}
                          title={
                            t.aktiv && t.ugyCount > 0
                              ? "Ehhez a tételhez ügyek tartoznak; előbb sorold át őket."
                              : undefined
                          }
                        >
                          {t.aktiv ? "Inaktiválás" : "Visszaállítás"}
                        </button>
                      </td>
                    )}
                  </tr>
                )
              )}
            </tbody>
          </table>
        </div>
      </div>

      {!canEdit && (
        <p className="note">
          Az irattári tervet tulajdonos vagy adminisztrátor módosíthatja. A megőrzési idő
          rövidítése végső soron döntés arról, hogy iratokat meg lehet semmisíteni — ezért
          nem mindenki jogosultsága.
        </p>
      )}
    </div>
  );
}

function TetelForm({
  draft,
  setDraft,
  withTetelszam,
  busy,
  onSave,
  onCancel,
}: {
  draft: Draft;
  setDraft: (d: Draft) => void;
  withTetelszam: boolean;
  busy: boolean;
  onSave: () => void;
  onCancel: () => void;
}) {
  const nemSelejtezheto = draft.orzesiIdo.trim() === "";

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-4">
        {withTetelszam && (
          <label>
            <span className="flabel">Tételszám</span>
            <input
              value={draft.tetelszam}
              onChange={(e) => setDraft({ ...draft, tetelszam: e.target.value })}
              placeholder="P-6"
              className="control w-28"
            />
          </label>
        )}
        <label className="min-w-64 flex-1">
          <span className="flabel">Megnevezés</span>
          <input
            value={draft.nev}
            onChange={(e) => setDraft({ ...draft, nev: e.target.value })}
            className="control"
          />
        </label>
        <label>
          <span className="flabel">Őrzési idő (év)</span>
          <input
            value={draft.orzesiIdo}
            onChange={(e) => setDraft({ ...draft, orzesiIdo: e.target.value })}
            inputMode="numeric"
            placeholder={NEM_SELEJTEZHETO_JEL}
            className="control w-28 tabular-nums"
          />
        </label>
      </div>

      {/* The checkbox is the honest control: "nem selejtezhető" is a decision,
          not the absence of one, and an empty number field does not look like
          a decision. */}
      <label className="flex items-center gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          checked={nemSelejtezheto}
          onChange={(e) => setDraft({ ...draft, orzesiIdo: e.target.checked ? "" : "8" })}
          className="checkbox"
        />
        Nem selejtezhető — a jel <strong>{NEM_SELEJTEZHETO_JEL}</strong> lesz, és az ügyek
        soha nem kerülnek selejtezésre javasolt állapotba.
      </label>

      <div className="flex flex-wrap gap-4">
        <label className="min-w-64 flex-1">
          <span className="flabel">Jogszabály</span>
          <input
            value={draft.jogszabaly}
            onChange={(e) => setDraft({ ...draft, jogszabaly: e.target.value })}
            placeholder="Szt. 169. § (2)"
            className="control"
          />
        </label>
        <label className="min-w-64 flex-1">
          <span className="flabel">Megjegyzés</span>
          <input
            value={draft.megjegyzes}
            onChange={(e) => setDraft({ ...draft, megjegyzes: e.target.value })}
            className="control"
          />
        </label>
      </div>

      <div className="flex gap-2">
        <button type="button" onClick={onSave} disabled={busy} className="btn btn-primary">
          Mentés
        </button>
        <button type="button" onClick={onCancel} disabled={busy} className="btn btn-secondary">
          Mégse
        </button>
      </div>
    </div>
  );
}
