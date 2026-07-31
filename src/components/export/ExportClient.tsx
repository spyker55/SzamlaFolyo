"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { DATE_BASIS_LABEL, type DateBasis } from "@/lib/export/period";
import type { CurrencyTotal } from "@/lib/export/csv";

type Props = {
  month: string;
  months: { value: string; label: string }[];
  basis: DateBasis;
  direction: "bejovo" | "kimeno" | null;
  from: string;
  to: string;
  count: number;
  limit: number;
  totals: CurrencyTotal[];
  kinds: { label: string; count: number }[];
  withoutFile: number;
  ervenytelen: number;
  nemKonyvelendo: number;
};

export function ExportClient(props: Props) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [busy, setBusy] = useState<"csv" | "zip" | null>(null);
  const [error, setError] = useState<string | null>(null);

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams({
      honap: props.month,
      alap: props.basis,
      ...(props.direction ? { irany: props.direction } : {}),
    });
    if (value) next.set(key, value);
    else next.delete(key);
    startTransition(() => router.push(`/export?${next.toString()}`));
  };

  // A download cannot be a plain link: the route answers with JSON on refusal
  // (period too large, nothing to export), and a link would navigate the user
  // to that JSON instead of showing the reason.
  const download = async (format: "csv" | "zip") => {
    setError(null);
    setBusy(format);
    try {
      const query = new URLSearchParams({
        from: props.from,
        to: props.to,
        basis: props.basis,
        format,
        ...(props.direction ? { direction: props.direction } : {}),
      });
      const response = await fetch(`/api/export?${query.toString()}`);
      if (!response.ok) {
        const body = await response.json().catch(() => null);
        setError(body?.error ?? "A letöltés nem sikerült.");
        return;
      }
      const blob = await response.blob();
      const name = filenameFrom(response.headers.get("Content-Disposition")) ?? `export.${format}`;
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = name;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch {
      setError("A letöltés nem sikerült.");
    } finally {
      setBusy(null);
    }
  };

  const empty = props.count === 0;

  return (
    <div className="mt-4 max-w-3xl space-y-4">
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex flex-wrap gap-4">
          <label className="text-sm">
            <span className="block text-xs text-gray-500">Időszak</span>
            <select
              value={props.month}
              onChange={(e) => setParam("honap", e.target.value)}
              className="mt-1 rounded-md border border-gray-300 px-2 py-1"
            >
              {props.months.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>

          <label className="text-sm">
            <span className="block text-xs text-gray-500">Mi alapján</span>
            <select
              value={props.basis}
              onChange={(e) => setParam("alap", e.target.value)}
              className="mt-1 rounded-md border border-gray-300 px-2 py-1"
            >
              <option value="erkezett">{DATE_BASIS_LABEL.erkezett}</option>
              <option value="kelt">{DATE_BASIS_LABEL.kelt}</option>
            </select>
          </label>

          <label className="text-sm">
            <span className="block text-xs text-gray-500">Irány</span>
            <select
              value={props.direction ?? ""}
              onChange={(e) => setParam("irany", e.target.value)}
              className="mt-1 rounded-md border border-gray-300 px-2 py-1"
            >
              <option value="">Mind</option>
              <option value="bejovo">Bejövő</option>
              <option value="kimeno">Kimenő</option>
            </select>
          </label>
        </div>

        <p className="mt-3 text-xs text-gray-500">
          {props.basis === "erkezett"
            ? "Az iratok beérkezési dátuma szerint — ez az, amit az iktatókönyv is mutat."
            : "Az iratok saját kelte szerint. Amelyik iratról a kelt nem derült ki, az ebből a listából kimarad."}
        </p>
      </div>

      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex flex-wrap items-baseline gap-3">
          <span className="text-sm text-gray-500">
            {props.from} – {props.to}
          </span>
          <span className="text-lg font-semibold">{props.count} irat</span>
          {props.totals.length === 0 && props.count > 0 && (
            <span className="text-sm text-gray-500">nincs könyvelendő összeg</span>
          )}
          {props.totals.map((t) => (
            <span key={t.currency} className="text-sm">
              nettó {formatAmountHu(t.net)} · ÁFA {formatAmountHu(t.vat)} ·{" "}
              <strong>bruttó {formatAmountHu(t.gross)} {t.currency}</strong>
            </span>
          ))}
        </div>

        {props.kinds.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            {props.kinds.map((k) => (
              <span
                key={k.label}
                className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700"
              >
                {k.label} · {k.count}
              </span>
            ))}
          </div>
        )}

        <p className="mt-3 text-xs text-gray-500">
          Az összeg csak a számviteli bizonylatokat tartalmazza: számla, előlegszámla,
          helyesbítő, sztornó és nyugta. A díjbekérő nem az — a rá kiállított számla ugyanazt
          az összeget hozza, tehát kétszer szerepelne a könyvelésben.
        </p>

        <ul className="mt-3 space-y-1 text-xs text-gray-500">
          {props.nemKonyvelendo > 0 && (
            <li>
              {props.nemKonyvelendo} irat nem számviteli bizonylat (díjbekérő, szállítólevél,
              szerződés…). A táblázatban benne van, a „Könyvelendő” oszlopban „nem” jelöléssel,
              de az összegbe nincs beleszámolva.
            </li>
          )}
          {props.ervenytelen > 0 && (
            <li>
              {props.ervenytelen} érvénytelenített irat is szerepel a listában, külön jelölve —
              az összesítésbe nincsenek beleszámolva.
            </li>
          )}
          {props.withoutFile > 0 && (
            <li>{props.withoutFile} irathoz nincs csatolt fájl, ezek a ZIP-ből kimaradnak.</li>
          )}
          {props.count >= props.limit && (
            <li className="text-amber-700">
              Az időszak elérte a {props.limit} iratos határt, válassz rövidebbet.
            </li>
          )}
        </ul>
      </div>

      {error && (
        <div className="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">
          {error}
        </div>
      )}

      <div className="flex flex-wrap gap-3">
        <button
          type="button"
          onClick={() => download("csv")}
          disabled={empty || busy !== null || pending}
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50"
        >
          {busy === "csv" ? "Készül…" : "Táblázat letöltése (CSV)"}
        </button>
        <button
          type="button"
          onClick={() => download("zip")}
          disabled={empty || busy !== null || pending}
          className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          {busy === "zip" ? "Készül…" : "Iratok + táblázat (ZIP)"}
        </button>
      </div>

      <p className="text-xs text-gray-500">
        A CSV pontosvesszővel tagolt, magyar tizedesvesszővel — a magyar Excel közvetlenül
        megnyitja. A ZIP az iratok eredeti fájljait tartalmazza, iktatószámmal kezdődő néven,
        és benne van ugyanez a táblázat is.
      </p>
    </div>
  );
}

function filenameFrom(header: string | null): string | null {
  if (!header) return null;
  const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(header);
  if (utf8) {
    try {
      return decodeURIComponent(utf8[1]);
    } catch {
      // Fall through to the ASCII form.
    }
  }
  const plain = /filename="([^"]+)"/i.exec(header);
  return plain ? plain[1] : null;
}
