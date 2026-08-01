"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { formatBankAccount } from "@/lib/partner/bank-account";
import { formatTaxNumber } from "@/lib/partner/identity";
import { STRENGTH_LABEL, type DuplicateStrength } from "@/lib/partner/duplicates";
import { letrehozPartner, type PartnerFields } from "@/lib/partner/actions";
import { EmptyState } from "@/components/ui/page";
import { IconPlus, IconSearch, IconUsers } from "@/components/ui/icons";

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
  biztos: "badge-red",
  valoszinu: "badge-amber",
  lehetseges: "badge-slate",
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
    <div className="space-y-4">
      {duplicates.length > 0 && (
        <div className="alert alert-warn">
          <div className="text-sm font-semibold">
            Lehetséges duplikátumok ({duplicates.length})
          </div>
          <p className="mt-1 text-xs">
            Az összevonás a partner adatlapján indítható, és bármikor visszavonható.
            Eltérő törzsszámú cégek soha nem kerülnek ide.
          </p>
          <ul className="mt-3 space-y-2">
            {duplicates.map((d) => (
              <li key={`${d.aId}-${d.bId}`} className="flex flex-wrap items-center gap-2 text-sm">
                <span className={`badge ${STRENGTH_STYLE[d.strength]}`}>
                  {STRENGTH_LABEL[d.strength]}
                </span>
                <Link href={`/partnerek/${d.aId}`} className="link">
                  {d.aName}
                </Link>
                <span className="text-slate-400">↔</span>
                <Link href={`/partnerek/${d.bId}`} className="link">
                  {d.bName}
                </Link>
                <span className="note">{d.reason}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-1.5">
          {ROLE_FILTERS.map((f) => (
            <button
              key={f.value}
              type="button"
              onClick={() => setRole(f.value)}
              className={`chip ${role === f.value ? "chip-on" : ""}`}
            >
              {f.label}
            </button>
          ))}
        </div>
        <div className="relative w-72">
          <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Keresés névre, adószámra, bankszámlára"
            className="control pl-9"
          />
        </div>
        <button
          type="button"
          onClick={() => setCreating((v) => !v)}
          className={`ml-auto btn ${creating ? "btn-secondary" : "btn-primary"}`}
        >
          {creating ? (
            "Mégse"
          ) : (
            <>
              <IconPlus className="h-4 w-4" />
              Új partner
            </>
          )}
        </button>
      </div>

      {creating && (
        <div className="card card-pad">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Név">
              <input
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                className="control"
              />
            </Field>
            <Field label="Adószám">
              <input
                value={draft.taxNumber}
                onChange={(e) => setDraft({ ...draft, taxNumber: e.target.value })}
                placeholder="12345678-1-23"
                className="control"
              />
            </Field>
            <Field label="Bankszámlaszám">
              <input
                value={draft.bankAccount}
                onChange={(e) => setDraft({ ...draft, bankAccount: e.target.value })}
                className="control"
              />
            </Field>
          </div>
          <div className="mt-4 flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2 text-slate-700">
              <input
                type="checkbox"
                className="checkbox"
                checked={draft.isSupplier}
                onChange={(e) => setDraft({ ...draft, isSupplier: e.target.checked })}
              />
              Szállító
            </label>
            <label className="flex items-center gap-2 text-slate-700">
              <input
                type="checkbox"
                className="checkbox"
                checked={draft.isCustomer}
                onChange={(e) => setDraft({ ...draft, isCustomer: e.target.checked })}
              />
              Vevő
            </label>
            <button
              type="button"
              onClick={create}
              disabled={pending}
              className="btn btn-primary ml-auto"
            >
              Létrehozás
            </button>
          </div>
          {error && (
            <p className="alert alert-error mt-3" role="alert">
              {error}
            </p>
          )}
        </div>
      )}

      {shown.length === 0 ? (
        <div className="card">
          <EmptyState icon={<IconUsers className="h-8 w-8" />}>
            {partners.length === 0 ? "Még nincs partner." : "Nincs találat."}
          </EmptyState>
        </div>
      ) : (
        <div className="card table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Név</th>
                <th className="th">Adószám</th>
                <th className="th">Bankszámla</th>
                <th className="th">Szerep</th>
                <th className="th text-right">Iratok</th>
                <th className="th">Utolsó irat</th>
                <th className="th text-right">Nyitott</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((p) => (
                <tr key={p.id} className="trow">
                  <td className="td">
                    <Link href={`/partnerek/${p.id}`} className="link font-medium">
                      {p.name}
                    </Link>
                  </td>
                  <td className="td tabular-nums">{formatTaxNumber(p.taxNumber) || "—"}</td>
                  <td className="td tabular-nums">{formatBankAccount(p.bankAccount) || "—"}</td>
                  <td className="td">
                    <span className="flex flex-wrap gap-1">
                      {p.isSupplier && <span className="badge badge-blue">Szállító</span>}
                      {p.isCustomer && <span className="badge badge-green">Vevő</span>}
                      {!p.isSupplier && !p.isCustomer && "—"}
                    </span>
                  </td>
                  <td className="td text-right tabular-nums">{p.iratCount}</td>
                  <td className="td tabular-nums">{p.lastIratAt ?? "—"}</td>
                  <td className="td text-right tabular-nums">
                    {p.open.length === 0 ? (
                      <span className="text-slate-300">—</span>
                    ) : (
                      p.open.map((t) => (
                        <div key={t.currency} className="whitespace-nowrap font-medium">
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
      <span className="flabel">{label}</span>
      {children}
    </label>
  );
}
