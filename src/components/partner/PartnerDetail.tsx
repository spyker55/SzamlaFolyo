"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useState, useTransition } from "react";
import { formatAmountHu } from "@/lib/format/amount";
import { docKindLabel } from "@/lib/domain/doc-kind";
import { bankAccountWarning, formatBankAccount } from "@/lib/partner/bank-account";
import { formatTaxNumber } from "@/lib/partner/identity";
import { mergeBlockedReason } from "@/lib/partner/duplicates";
import { UGY_STATUS_LABEL, type UgyStatus } from "@/lib/ugy/status";
import {
  mentPartner,
  osszevonPartner,
  visszavonOsszevonas,
  type PartnerFields,
} from "@/lib/partner/actions";

type Partner = {
  id: string;
  name: string;
  taxNumber: string | null;
  euTaxNumber: string | null;
  bankAccount: string | null;
  address: string | null;
  email: string | null;
  country: string | null;
  isSupplier: boolean;
  isCustomer: boolean;
  paymentTermDays: number | null;
  note: string | null;
  retired: boolean;
  mergedIntoId: string | null;
  mergedIntoName: string | null;
};

type Irat = {
  id: string;
  ugyId: string | null;
  iktatoszam: string | null;
  docKind: string | null;
  direction: string | null;
  targy: string | null;
  erkezettAt: string | null;
  dueDate: string | null;
  grossAmount: number | null;
  currency: string | null;
  status: string;
  fizetveAt: string | null;
};

type Ugy = {
  id: string;
  iktatoszam: string;
  targy: string;
  status: string;
  hatarido: string | null;
};

type Merge = {
  id: string;
  survivorId: string;
  loserId: string;
  survivorName: string;
  loserName: string;
  documentCount: number;
  ugyCount: number;
  createdAt: string;
  undoneAt: string | null;
};

type Candidate = { id: string; name: string; taxNumber: string | null };

export function PartnerDetail({
  partner,
  iratok,
  ugyek,
  merges,
  candidates,
  canMerge,
}: {
  partner: Partner;
  iratok: Irat[];
  ugyek: Ugy[];
  merges: Merge[];
  candidates: Candidate[];
  canMerge: boolean;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [loserId, setLoserId] = useState("");
  const [confirming, setConfirming] = useState(false);

  const [fields, setFields] = useState<PartnerFields>({
    name: partner.name,
    taxNumber: partner.taxNumber ?? "",
    euTaxNumber: partner.euTaxNumber ?? "",
    bankAccount: partner.bankAccount ?? "",
    address: partner.address ?? "",
    email: partner.email ?? "",
    country: partner.country ?? "",
    isSupplier: partner.isSupplier,
    isCustomer: partner.isCustomer,
    paymentTermDays: partner.paymentTermDays === null ? "" : String(partner.paymentTermDays),
    note: partner.note ?? "",
  });

  const loser = candidates.find((c) => c.id === loserId) ?? null;
  const blocked = useMemo(() => {
    if (!loser) return null;
    return mergeBlockedReason(
      { id: partner.id, name: partner.name, taxNumber: partner.taxNumber },
      loser
    );
  }, [loser, partner.id, partner.name, partner.taxNumber]);

  const run = (fn: () => Promise<{ ok: boolean; error?: string }>, after?: () => void) => {
    setError(null);
    setBusy(true);
    startTransition(async () => {
      const result = await fn();
      setBusy(false);
      if (!result.ok) {
        setError(result.error ?? "Ismeretlen hiba.");
        return;
      }
      after?.();
      router.refresh();
    });
  };

  const totals = useMemo(() => {
    const byCurrency = new Map<string, number>();
    for (const d of iratok) {
      if (d.status !== "iktatva" || d.grossAmount === null || !d.currency) continue;
      byCurrency.set(d.currency, (byCurrency.get(d.currency) ?? 0) + d.grossAmount);
    }
    return [...byCurrency.entries()].sort((a, b) => a[0].localeCompare(b[0], "hu"));
  }, [iratok]);

  return (
    <div className="space-y-6">
      <div>
        <Link href="/partnerek" className="text-sm text-blue-700 hover:underline">
          ← Partnerek
        </Link>
        <div className="mt-1 flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold">{partner.name}</h1>
          {partner.isSupplier && <Badge>Szállító</Badge>}
          {partner.isCustomer && <Badge>Vevő</Badge>}
          {partner.retired && (
            <span className="rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-700">
              Nem aktív
            </span>
          )}
        </div>
        {partner.mergedIntoId && (
          <p className="mt-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            Ez a partner össze lett vonva ide:{" "}
            <Link
              href={`/partnerek/${partner.mergedIntoId}`}
              className="text-blue-700 hover:underline"
            >
              {partner.mergedIntoName ?? "másik partner"}
            </Link>
            . Az adatai addig nem szerkeszthetők, amíg az összevonást vissza nem vonod.
          </p>
        )}
      </div>

      {error && (
        <p className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
          {error}
        </p>
      )}

      <section className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-medium text-gray-700">Törzsadatok</h2>
          {!partner.mergedIntoId && (
            <button
              type="button"
              onClick={() => setEditing((v) => !v)}
              className="text-sm text-blue-700 hover:underline"
            >
              {editing ? "Mégse" : "Szerkesztés"}
            </button>
          )}
        </div>

        {editing ? (
          <div className="mt-3 space-y-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="Név">
                <Input
                  value={fields.name}
                  onChange={(v) => setFields({ ...fields, name: v })}
                />
              </Field>
              <Field label="Adószám">
                <Input
                  value={fields.taxNumber}
                  onChange={(v) => setFields({ ...fields, taxNumber: v })}
                  placeholder="12345678-1-23"
                />
              </Field>
              <Field label="Közösségi adószám">
                <Input
                  value={fields.euTaxNumber}
                  onChange={(v) => setFields({ ...fields, euTaxNumber: v })}
                />
              </Field>
              <Field label="Bankszámlaszám">
                <Input
                  value={fields.bankAccount}
                  onChange={(v) => setFields({ ...fields, bankAccount: v })}
                />
                {/* A warning, not a refusal: the check digit is a good typo
                    detector, but a wrong one must never block a real account. */}
                {bankAccountWarning(fields.bankAccount) && (
                  <span className="mt-1 block text-xs text-amber-700">
                    {bankAccountWarning(fields.bankAccount)}
                  </span>
                )}
              </Field>
              <Field label="Cím">
                <Input
                  value={fields.address}
                  onChange={(v) => setFields({ ...fields, address: v })}
                />
              </Field>
              <Field label="Ország">
                <Input
                  value={fields.country}
                  onChange={(v) => setFields({ ...fields, country: v })}
                  placeholder="HU"
                />
              </Field>
              <Field label="E-mail">
                <Input
                  value={fields.email}
                  onChange={(v) => setFields({ ...fields, email: v })}
                />
              </Field>
              <Field label="Fizetési határidő (nap)">
                <Input
                  value={fields.paymentTermDays}
                  onChange={(v) => setFields({ ...fields, paymentTermDays: v })}
                />
              </Field>
            </div>
            <Field label="Megjegyzés">
              <textarea
                value={fields.note}
                onChange={(e) => setFields({ ...fields, note: e.target.value })}
                rows={2}
                className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
              />
            </Field>
            <div className="flex flex-wrap items-center gap-4 text-sm">
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={fields.isSupplier}
                  onChange={(e) => setFields({ ...fields, isSupplier: e.target.checked })}
                />
                Szállító
              </label>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={fields.isCustomer}
                  onChange={(e) => setFields({ ...fields, isCustomer: e.target.checked })}
                />
                Vevő
              </label>
              <button
                type="button"
                disabled={busy}
                onClick={() =>
                  run(() => mentPartner(partner.id, fields), () => setEditing(false))
                }
                className="ml-auto rounded-md bg-blue-700 px-4 py-1 text-sm text-white disabled:opacity-50"
              >
                Mentés
              </button>
            </div>
          </div>
        ) : (
          <dl className="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <Row label="Adószám" value={formatTaxNumber(partner.taxNumber)} />
            <Row label="Közösségi adószám" value={partner.euTaxNumber} />
            <Row label="Bankszámlaszám" value={formatBankAccount(partner.bankAccount)} />
            <Row label="Cím" value={partner.address} />
            <Row label="Ország" value={partner.country} />
            <Row label="E-mail" value={partner.email} />
            <Row
              label="Fizetési határidő"
              value={partner.paymentTermDays === null ? null : `${partner.paymentTermDays} nap`}
            />
            <Row label="Megjegyzés" value={partner.note} />
          </dl>
        )}
      </section>

      {canMerge && !partner.mergedIntoId && !partner.retired && candidates.length > 0 && (
        <section className="rounded-lg border border-gray-200 bg-white p-4">
          <h2 className="text-sm font-medium text-gray-700">Összevonás</h2>
          <p className="mt-1 text-xs text-gray-500">
            A kiválasztott partner iratai és ügyei ide kerülnek át, a másik sor pedig
            nem aktív lesz. Az összevonás bármikor visszavonható, és pontosan azokat a
            sorokat viszi vissza, amelyeket elmozdított.
          </p>
          <div className="mt-3 flex flex-wrap items-center gap-3">
            <select
              value={loserId}
              onChange={(e) => {
                setLoserId(e.target.value);
                setConfirming(false);
              }}
              className="w-96 rounded-md border border-gray-300 px-2 py-1 text-sm"
            >
              <option value="">Válaszd ki a beolvasztandó partnert…</option>
              {candidates.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                  {c.taxNumber ? ` — ${formatTaxNumber(c.taxNumber)}` : ""}
                </option>
              ))}
            </select>
            {loser && !blocked && !confirming && (
              <button
                type="button"
                onClick={() => setConfirming(true)}
                className="rounded-md border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50"
              >
                Összevonás…
              </button>
            )}
          </div>

          {blocked && <p className="mt-3 text-sm text-red-600">{blocked}</p>}

          {loser && !blocked && confirming && (
            <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
              <p className="text-amber-900">
                <strong>{loser.name}</strong> minden irata és ügye átkerül ide:{" "}
                <strong>{partner.name}</strong>. A(z) {loser.name} sor nem aktív lesz.
              </p>
              <div className="mt-2 flex gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() =>
                    run(() => osszevonPartner(partner.id, loser.id), () => {
                      setConfirming(false);
                      setLoserId("");
                    })
                  }
                  className="rounded-md bg-amber-700 px-3 py-1 text-sm text-white disabled:opacity-50"
                >
                  Összevonás
                </button>
                <button
                  type="button"
                  onClick={() => setConfirming(false)}
                  className="rounded-md border border-gray-300 px-3 py-1 text-sm text-gray-700"
                >
                  Mégse
                </button>
              </div>
            </div>
          )}
        </section>
      )}

      {merges.length > 0 && (
        <section className="rounded-lg border border-gray-200 bg-white p-4">
          <h2 className="text-sm font-medium text-gray-700">Összevonások</h2>
          <ul className="mt-3 space-y-2 text-sm">
            {merges.map((m) => (
              <li key={m.id} className="flex flex-wrap items-center gap-2">
                <span className="text-gray-500">{m.createdAt.slice(0, 10)}</span>
                <span>
                  <Link
                    href={`/partnerek/${m.loserId}`}
                    className="text-blue-700 hover:underline"
                  >
                    {m.loserName}
                  </Link>
                  {" → "}
                  <Link
                    href={`/partnerek/${m.survivorId}`}
                    className="text-blue-700 hover:underline"
                  >
                    {m.survivorName}
                  </Link>
                </span>
                <span className="text-xs text-gray-500">
                  {m.documentCount} irat, {m.ugyCount} ügy
                </span>
                {m.undoneAt ? (
                  <span className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">
                    visszavonva {m.undoneAt.slice(0, 10)}
                  </span>
                ) : (
                  canMerge && (
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => run(() => visszavonOsszevonas(m.id))}
                      className="text-xs text-blue-700 hover:underline disabled:opacity-50"
                    >
                      Visszavonás
                    </button>
                  )
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="rounded-lg border border-gray-200 bg-white">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-2">
          <h2 className="text-sm font-medium text-gray-700">Iratok ({iratok.length})</h2>
          <div className="text-sm text-gray-600">
            {totals.map(([currency, amount]) => (
              <span key={currency} className="ml-3 tabular-nums">
                {formatAmountHu(amount)} {currency}
              </span>
            ))}
          </div>
        </div>
        {iratok.length === 0 ? (
          <p className="p-6 text-center text-gray-400">Ehhez a partnerhez még nincs irat.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                  <th className="px-4 py-2">Iktatószám</th>
                  <th className="px-4 py-2">Típus</th>
                  <th className="px-4 py-2">Tárgy</th>
                  <th className="px-4 py-2">Érkezett</th>
                  <th className="px-4 py-2">Határidő</th>
                  <th className="px-4 py-2 text-right">Bruttó</th>
                  <th className="px-4 py-2">Állapot</th>
                </tr>
              </thead>
              <tbody>
                {iratok.map((d) => (
                  <tr
                    key={d.id}
                    className="border-b border-gray-100 last:border-0 hover:bg-gray-50"
                  >
                    <td
                      className={`px-4 py-2 tabular-nums ${
                        d.status === "ervenytelenitve" ? "text-gray-400 line-through" : ""
                      }`}
                    >
                      {d.ugyId ? (
                        <Link
                          href={`/ugyek/${d.ugyId}`}
                          className="text-blue-700 hover:underline"
                        >
                          {d.iktatoszam ?? "—"}
                        </Link>
                      ) : (
                        d.iktatoszam ?? "—"
                      )}
                    </td>
                    <td className="px-4 py-2 text-gray-600">{docKindLabel(d.docKind)}</td>
                    <td className="px-4 py-2">{d.targy ?? "—"}</td>
                    <td className="px-4 py-2 text-gray-600">{d.erkezettAt ?? "—"}</td>
                    <td className="px-4 py-2 text-gray-600">{d.dueDate ?? "—"}</td>
                    <td className="px-4 py-2 text-right tabular-nums">
                      {d.grossAmount === null
                        ? "—"
                        : `${formatAmountHu(d.grossAmount)} ${d.currency ?? ""}`}
                    </td>
                    <td className="px-4 py-2 text-xs text-gray-500">
                      {d.status === "ervenytelenitve"
                        ? "Érvénytelenítve"
                        : d.fizetveAt
                          ? `Fizetve ${d.fizetveAt}`
                          : d.status === "iktatva"
                            ? "Iktatva"
                            : "Feldolgozás alatt"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {ugyek.length > 0 && (
        <section className="rounded-lg border border-gray-200 bg-white">
          <h2 className="border-b border-gray-200 px-4 py-2 text-sm font-medium text-gray-700">
            Ügyek ({ugyek.length})
          </h2>
          <ul className="divide-y divide-gray-100">
            {ugyek.map((u) => (
              <li key={u.id} className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                <Link
                  href={`/ugyek/${u.id}`}
                  className="tabular-nums text-blue-700 hover:underline"
                >
                  {u.iktatoszam}
                </Link>
                <span className="flex-1">{u.targy || "—"}</span>
                <span className="text-xs text-gray-500">
                  {UGY_STATUS_LABEL[u.status as UgyStatus] ?? u.status}
                </span>
                <span className="w-24 text-right text-xs text-gray-500">
                  {u.hatarido ?? ""}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

function Badge({ children }: { children: React.ReactNode }) {
  return (
    <span className="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-800">{children}</span>
  );
}

function Row({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-xs uppercase text-gray-500">{label}</dt>
      <dd className="text-gray-900">{value || "—"}</dd>
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

function Input({
  value,
  onChange,
  placeholder,
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
}) {
  return (
    <input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
    />
  );
}
