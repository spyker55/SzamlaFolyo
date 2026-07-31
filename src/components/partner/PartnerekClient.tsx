"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { formatBankAccount } from "@/lib/partner/bank-account";
import { formatTaxNumber } from "@/lib/partner/identity";
import { STRENGTH_LABEL, type DuplicateStrength } from "@/lib/partner/duplicates";
import { letrehozPartner, type PartnerFields } from "@/lib/partner/actions";

export type PartnerRow = {
  id: string;
  name: string;
  taxNumber: string | null;
  bankAccount: string | null;
  isSupplier: boolean;
  isCustomer: boolean;
  paymentTermDays: number | null;
  iratCount: number;
  lastIratAt: string | null;
  open: { currency: string; amount: number }[];
};

export type DuplicateView = {
  aId: string;
  bId: string;
  aName: string;
  bName: string;
  strength: DuplicateStrength;
  reason: string;
};

const ROLE_FILTERS = [
  { value: "mind", label: "Mind" },
  { value: "szallito", label: "Szállító" },
  { value: "vevo", label: "Vevő" },
] as const;

const STRENGTH_STYLE: Record<DuplicateStrength, string> = {
  biztos: "bg-red-100 text-red-800",
  valoszinu: "bg-amber-100 text-amber-800",
  lehetseges: "bg-gray-100 text-gray-600",
};

const EMPTY: PartnerFields = {
  name: "",
  taxNumber: "",
  euTaxNumber: "",
  bankAccount: "",
  address: "",
  email: "",
  country: "",
  isSupplier: true,
  isCustomer: false,
  paymentTermDays: "",
  note: "",
};

export function PartnerekClient({
  partners,
  duplicates,
}: {
  partners: PartnerRow[];
  duplicates: DuplicateView[];
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [search, setSearch] = useState("");
  const [role, setRole] = useState<string>("mind");
  const [creating, setCreating] = useState(false);
  const [draft, setDraft] = useState<PartnerFields>(EMPTY);
  const [error, setError] = useState<string | null>(null);

  const shown = useMemo(() => {
    const q = search.trim().toLowerCase();
    return partners.filter((p) => {
      if (role === "szallito" && !p.isSupplier) return false;
      if (role === "vevo" && !p.isCustomer) return false;
      if (q === "") return true;
      return (
        p.name.toLowerCase().includes(q) ||
        (p.taxNumber ?? "").toLowerCase().includes(q) ||
        (p.bankAccount ?? "").toLowerCase().includes(q)
      );
    });
  }, [partners, search, role]);

  const create = () => {
    setError(null);
    startTransition(async () => {
      const result = await letrehozPartner(draft);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      setDraft(EMPTY);
      setCreating(false);
      router.refresh();
      if (result.id) router.push(`/partnerek/${result.id}`);
    });
  };

  return (
    <div className="mt-4 space-y-4">
      {duplicates.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
          <div className="text-sm font-medium text-amber-900">
            Lehetséges duplikátumok ({duplicates.length})
          </div>
          <p className="mt-1 text-xs text-amber-800">
            Az összevonás a partner adatlapján indítható, és bármikor visszavonható.
            Eltérő törzsszámú cégek soha nem kerülnek ide.
          </p>
          <ul className="mt-3 space-y-2">
            {duplicates.map((d) => (
              <li key={`${d.aId}-${d.bId}`} className="flex flex-wrap items-center gap-2 text-sm">
                <span
                  className={`rounded px-2 py-0.5 text-xs ${STRENGTH_STYLE[d.strength]}`}
                >
                  {STRENGTH_LABEL[d.strength]}
                </span>
                <Link href={`/partnerek/${d.aId}`} className="text-blue-700 hover:underline">
                  {d.aName}
                </Link>
                <span className="text-gray-400">↔</span>
                <Link href={`/partnerek/${d.bId}`} className="text-blue-700 hover:underline">
                  {d.bName}
                </Link>
                <span className="text-xs text-gray-500">{d.reason}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-1">
          {ROLE_FILTERS.map((f) => (
            <button
              key={f.value}
              type="button"
              onClick={() => setRole(f.value)}
              className={`rounded-md px-3 py-1 text-sm ${
                role === f.value
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
          placeholder="Keresés névre, adószámra, bankszámlára"
          className="w-72 rounded-md border border-gray-300 px-3 py-1 text-sm"
        />
        <button
          type="button"
          onClick={() => setCreating((v) => !v)}
          className="ml-auto rounded-md border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50"
        >
          {creating ? "Mégse" : "Új partner"}
        </button>
      </div>

      {creating && (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Név">
              <input
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
              />
            </Field>
            <Field label="Adószám">
              <input
                value={draft.taxNumber}
                onChange={(e) => setDraft({ ...draft, taxNumber: e.target.value })}
                placeholder="12345678-1-23"
                className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
              />
            </Field>
            <Field label="Bankszámlaszám">
              <input
                value={draft.bankAccount}
                onChange={(e) => setDraft({ ...draft, bankAccount: e.target.value })}
                className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
              />
            </Field>
          </div>
          <div className="mt-3 flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={draft.isSupplier}
                onChange={(e) => setDraft({ ...draft, isSupplier: e.target.checked })}
              />
              Szállító
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={draft.isCustomer}
                onChange={(e) => setDraft({ ...draft, isCustomer: e.target.checked })}
              />
              Vevő
            </label>
            <button
              type="button"
              onClick={create}
              disabled={pending}
              className="ml-auto rounded-md bg-blue-700 px-4 py-1 text-sm text-white disabled:opacity-50"
            >
              Létrehozás
            </button>
          </div>
          {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </div>
      )}

      {shown.length === 0 ? (
        <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-gray-400">
          {partners.length === 0 ? "Még nincs partner." : "Nincs találat."}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
              <tr>
                <th className="px-4 py-2">Név</th>
                <th className="px-4 py-2">Adószám</th>
                <th className="px-4 py-2">Bankszámla</th>
                <th className="px-4 py-2">Szerep</th>
                <th className="px-4 py-2 text-right">Iratok</th>
                <th className="px-4 py-2">Utolsó irat</th>
                <th className="px-4 py-2 text-right">Nyitott</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((p) => (
                <tr key={p.id} className="border-b border-gray-100 last:border-0 hover:bg-gray-50">
                  <td className="px-4 py-2">
                    <Link
                      href={`/partnerek/${p.id}`}
                      className="font-medium text-blue-700 hover:underline"
                    >
                      {p.name}
                    </Link>
                  </td>
                  <td className="px-4 py-2 tabular-nums text-gray-600">
                    {formatTaxNumber(p.taxNumber) || "—"}
                  </td>
                  <td className="px-4 py-2 tabular-nums text-gray-600">
                    {formatBankAccount(p.bankAccount) || "—"}
                  </td>
                  <td className="px-4 py-2 text-gray-600">
                    {[p.isSupplier ? "Szállító" : null, p.isCustomer ? "Vevő" : null]
                      .filter(Boolean)
                      .join(", ") || "—"}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">{p.iratCount}</td>
                  <td className="px-4 py-2 text-gray-600">{p.lastIratAt ?? "—"}</td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {p.open.length === 0 ? (
                      <span className="text-gray-300">—</span>
                    ) : (
                      p.open.map((t) => (
                        <div key={t.currency} className="whitespace-nowrap">
                          {formatAmountHu(t.amount)} {t.currency}
                        </div>
                      ))
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs uppercase text-gray-500">{label}</span>
      {children}
    </label>
  );
}
