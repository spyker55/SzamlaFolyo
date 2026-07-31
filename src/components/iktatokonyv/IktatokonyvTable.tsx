"use client";

import { useMemo, useState } from "react";
import { DOC_KIND_OPTIONS, docKindLabel } from "@/lib/domain/doc-kind";

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
export function Iktatokonyv({ rows }: { rows: IktatokonyvRow[] }) {
  const [query, setQuery] = useState("");
  const [direction, setDirection] = useState("");
  const [docKind, setDocKind] = useState("");

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
    <div className="mt-4 space-y-3">
      <div className="flex flex-wrap gap-2">
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Keresés: iktatószám, beküldő, tárgy…"
          className="w-72 rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:outline-none"
        />
        <select
          value={direction}
          onChange={(e) => setDirection(e.target.value)}
          className="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
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
          className="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
        >
          <option value="">Minden fajta</option>
          {DOC_KIND_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <span className="ml-auto self-center text-xs text-gray-400">
          {filtered.length} tétel
        </span>
      </div>

      <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table className="w-full whitespace-nowrap text-sm">
          <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
            <tr>
              <th className="px-3 py-2">Sorszám</th>
              <th className="px-3 py-2">Előadó</th>
              <th className="px-3 py-2">Irattári jel</th>
              <th className="px-3 py-2">Érkezett</th>
              <th className="px-3 py-2">Beküldő neve</th>
              <th className="px-3 py-2">Típus</th>
              <th className="px-3 py-2">Irat száma</th>
              <th className="px-3 py-2">Mell. db</th>
              <th className="px-3 py-2">Tárgy</th>
              <th className="px-3 py-2">Kezelési feljegyzések</th>
              <th className="px-3 py-2">Határidő</th>
              <th className="px-3 py-2">Irattárba helyezés</th>
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 && (
              <tr>
                <td colSpan={12} className="px-3 py-8 text-center text-gray-400">
                  Nincs iktatott irat.
                </td>
              </tr>
            )}
            {filtered.map((r) => (
              <tr
                key={r.id}
                className={`border-b border-gray-100 last:border-0 ${
                  r.ervenytelen ? "text-gray-400 line-through" : ""
                }`}
              >
                <td className="px-3 py-2 font-medium">{r.iktatoszam}</td>
                <td className="px-3 py-2">{r.eloado}</td>
                <td className="px-3 py-2">{r.irattariJel}</td>
                <td className="px-3 py-2">{r.erkezett}</td>
                <td className="px-3 py-2">{r.bekuldo}</td>
                <td className="px-3 py-2">{docKindLabel(r.docKind)}</td>
                <td className="px-3 py-2">{r.iratSzama}</td>
                <td className="px-3 py-2 text-center">{r.mellekletDb}</td>
                <td className="max-w-xs truncate px-3 py-2" title={r.targy}>
                  {r.targy}
                </td>
                <td className="max-w-xs truncate px-3 py-2" title={r.kezelesiFeljegyzes}>
                  {r.kezelesiFeljegyzes}
                </td>
                <td className="px-3 py-2">{r.hatarido}</td>
                <td className="px-3 py-2">{r.irattarbaHelyezve}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
