"use client";

import { useRouter } from "next/navigation";
import { useTransition } from "react";
import { AUDIT_FILTERS } from "@/lib/audit/labels";
import { AUDIT_PERIODS } from "@/lib/audit/query";

// The filters live in the URL rather than in component state: the log is the
// thing people send each other a link to ("nézd meg, mi történt kedden"), and
// state that only exists in one browser tab cannot be sent.
export function NaploFilters({
  tipus,
  ki,
  idoszak,
  members,
  filtering,
}: {
  tipus: string;
  ki: string;
  idoszak: string;
  members: { id: string; name: string }[];
  filtering: boolean;
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  const go = (next: URLSearchParams) => {
    const query = next.toString();
    startTransition(() => router.push(query ? `/naplo?${query}` : "/naplo"));
  };

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams({ tipus, ki, idoszak });
    next.set(key, value);
    // Paging starts over: page 3 of the old filter is not page 3 of the new one.
    next.delete("oldal");
    for (const [k, v] of [...next.entries()]) if (!v) next.delete(k);
    go(next);
  };

  return (
    <div className="card card-pad mb-4">
      <div className="flex flex-wrap items-end gap-4">
        <label>
          <span className="flabel">Mire vonatkozik</span>
          <select
            value={tipus}
            onChange={(e) => setParam("tipus", e.target.value)}
            className="control w-auto"
            disabled={pending}
          >
            <option value="">Minden esemény</option>
            {AUDIT_FILTERS.map((f) => (
              <option key={f.value} value={f.value}>
                {f.label}
              </option>
            ))}
          </select>
        </label>

        <label>
          <span className="flabel">Ki</span>
          <select
            value={ki}
            onChange={(e) => setParam("ki", e.target.value)}
            className="control w-auto"
            disabled={pending}
          >
            <option value="">Mindenki</option>
            {members.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </label>

        <label>
          <span className="flabel">Időszak</span>
          <select
            value={idoszak}
            onChange={(e) => setParam("idoszak", e.target.value)}
            className="control w-auto"
            disabled={pending}
          >
            {AUDIT_PERIODS.map((p) => (
              <option key={p.value} value={p.value}>
                {p.label}
              </option>
            ))}
          </select>
        </label>

        {filtering && (
          <button
            type="button"
            onClick={() => go(new URLSearchParams())}
            className="btn btn-ghost"
            disabled={pending}
          >
            Szűrők törlése
          </button>
        )}
      </div>

      <p className="note mt-3">
        A rendszer által végzett műveletek — e-mailben érkezett irat, AI feldolgozás — a
        „Rendszer” néven szerepelnek, mert nem egy felhasználó indította őket.
      </p>
    </div>
  );
}
