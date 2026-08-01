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
import { EmptyState } from "@/components/ui/page";
import { IconArrowLeft } from "@/components/ui/icons";

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
    <div className="space-y-5">
      <div>
        <Link href="/partnerek" className="link inline-flex items-center gap-1 text-sm">
          <IconArrowLeft className="h-4 w-4" />
          Partnerek
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            {partner.name}
          </h1>
          {partner.isSupplier && <span className="badge badge-blue">Szállító</span>}
          {partner.isCustomer && <span className="badge badge-green">Vevő</span>}
          {partner.retired && <span className="badge badge-slate">Nem aktív</span>}
        </div>
        {partner.mergedIntoId && (
          <p className="alert alert-muted mt-3">
            Ez a partner össze lett vonva ide:{" "}
            <Link href={`/partnerek/${partner.mergedIntoId}`} className="link">
              {partner.mergedIntoName ?? "másik partner"}
            </Link>
            . Az adatai addig nem szerkeszthetők, amíg az összevonást vissza nem vonod.
          </p>
        )}
      </div>

      {error && (
        <p className="alert alert-error" role="alert">
          {error}
        </p>
      )}

      <section className="card card-pad">
        <div className="flex items-center justify-between">
          <h2 className="card-title">Törzsadatok</h2>
          {!partner.mergedIntoId && (
            <button
              type="button"
              onClick={() => setEditing((v) => !v)}
              className="btn btn-ghost btn-sm"
            >
              {editing ? "Mégse" : "Szerkesztés"}
            </button>
          )}
        </div>

        {editing ? (
          <div className="mt-4 space-y-4">
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
                className="control"
              />
            </Field>
            <div className="flex flex-wrap items-center gap-4 text-sm">
              <label className="flex items-center gap-2 text-slate-700">
                <input
                  type="checkbox"
                  className="checkbox"
                  checked={fields.isSupplier}
                  onChange={(e) => setFields({ ...fields, isSupplier: e.target.checked })}
                />
                Szállító
              </label>
              <label className="flex items-center gap-2 text-slate-700">
                <input
                  type="checkbox"
                  className="checkbox"
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
                className="btn btn-primary ml-auto"
              >
                Mentés
              </button>
            </div>
          </div>
        ) : (
          <dl className="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
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
        <section className="card card-pad">
          <h2 className="card-title">Összevonás</h2>
          <p className="note mt-1">
            A kiválasztott partner iratai és ügyei ide kerülnek át, a másik sor pedig
            nem aktív lesz. Az összevonás bármikor visszavonható, és pontosan azokat a
            sorokat viszi vissza, amelyeket elmozdított.
          </p>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <select
              value={loserId}
              onChange={(e) => {
                setLoserId(e.target.value);
                setConfirming(false);
              }}
              className="control w-full sm:w-96"
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
                className="btn btn-secondary"
              >
                Összevonás…
              </button>
            )}
          </div>

          {blocked && <p className="alert alert-error mt-3">{blocked}</p>}

          {loser && !blocked && confirming && (
            <div className="alert alert-warn mt-3">
              <p>
                <strong>{loser.name}</strong> minden irata és ügye átkerül ide:{" "}
                <strong>{partner.name}</strong>. A(z) {loser.name} sor nem aktív lesz.
              </p>
              <div className="mt-3 flex gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() =>
                    run(() => osszevonPartner(partner.id, loser.id), () => {
                      setConfirming(false);
                      setLoserId("");
                    })
                  }
                  className="btn btn-warn"
                >
                  Összevonás
                </button>
                <button
                  type="button"
                  onClick={() => setConfirming(false)}
                  className="btn btn-secondary"
                >
                  Mégse
                </button>
              </div>
            </div>
          )}
        </section>
      )}

      {merges.length > 0 && (
        <section className="card card-pad">
          <h2 className="card-title">Összevonások</h2>
          <ul className="mt-3 space-y-2 text-sm">
            {merges.map((m) => (
              <li key={m.id} className="flex flex-wrap items-center gap-2">
                <span className="tabular-nums text-slate-500">
                  {m.createdAt.slice(0, 10)}
                </span>
                <span>
                  <Link href={`/partnerek/${m.loserId}`} className="link">
                    {m.loserName}
                  </Link>
                  {" → "}
                  <Link href={`/partnerek/${m.survivorId}`} className="link">
                    {m.survivorName}
                  </Link>
                </span>
                <span className="note">
                  {m.documentCount} irat, {m.ugyCount} ügy
                </span>
                {m.undoneAt ? (
                  <span className="badge badge-slate">
                    visszavonva {m.undoneAt.slice(0, 10)}
                  </span>
                ) : (
                  canMerge && (
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => run(() => visszavonOsszevonas(m.id))}
                      className="btn btn-ghost btn-sm"
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

      <section className="card">
        <div className="card-head">
          <h2 className="card-title">Iratok ({iratok.length})</h2>
          <div className="text-sm tabular-nums text-slate-600">
            {totals.map(([currency, amount]) => (
              <span key={currency} className="ml-3">
                {formatAmountHu(amount)} {currency}
              </span>
            ))}
          </div>
        </div>
        {iratok.length === 0 ? (
          <EmptyState>Ehhez a partnerhez még nincs irat.</EmptyState>
        ) : (
          <div className="table-scroll">
            <table className="tbl">
              <thead className="thead">
                <tr>
                  <th className="th">Iktatószám</th>
                  <th className="th">Típus</th>
                  <th className="th">Tárgy</th>
                  <th className="th">Érkezett</th>
                  <th className="th">Határidő</th>
                  <th className="th text-right">Bruttó</th>
                  <th className="th">Állapot</th>
                </tr>
              </thead>
              <tbody>
                {iratok.map((d) => (
                  <tr key={d.id} className="trow">
                    <td
                      className={`td tabular-nums ${
                        d.status === "ervenytelenitve" ? "text-slate-400 line-through" : ""
                      }`}
                    >
                      {d.ugyId ? (
                        <Link href={`/ugyek/${d.ugyId}`} className="link">
                          {d.iktatoszam ?? "—"}
                        </Link>
                      ) : (
                        d.iktatoszam ?? "—"
                      )}
                    </td>
                    <td className="td">{docKindLabel(d.docKind)}</td>
                    <td className="td">{d.targy ?? "—"}</td>
                    <td className="td tabular-nums">{d.erkezettAt ?? "—"}</td>
                    <td className="td tabular-nums">{d.dueDate ?? "—"}</td>
                    <td className="td text-right tabular-nums">
                      {d.grossAmount === null
                        ? "—"
                        : `${formatAmountHu(d.grossAmount)} ${d.currency ?? ""}`}
                    </td>
                    <td className="td">
                      {d.status === "ervenytelenitve" ? (
                        <span className="badge badge-red">Érvénytelenítve</span>
                      ) : d.fizetveAt ? (
                        <span className="badge badge-green">Fizetve {d.fizetveAt}</span>
                      ) : d.status === "iktatva" ? (
                        <span className="badge badge-slate">Iktatva</span>
                      ) : (
                        <span className="badge badge-blue">Feldolgozás alatt</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {ugyek.length > 0 && (
        <section className="card">
          <div className="card-head">
            <h2 className="card-title">Ügyek ({ugyek.length})</h2>
          </div>
          <ul className="divide-y divide-slate-100">
            {ugyek.map((u) => (
              <li
                key={u.id}
                className="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm sm:px-5"
              >
                <Link href={`/ugyek/${u.id}`} className="link tabular-nums">
                  {u.iktatoszam}
                </Link>
                <span className="flex-1 text-slate-700">{u.targy || "—"}</span>
                <span className="note">
                  {UGY_STATUS_LABEL[u.status as UgyStatus] ?? u.status}
                </span>
                <span className="note w-24 text-right tabular-nums">{u.hatarido ?? ""}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="flabel">{label}</dt>
      <dd className="text-slate-900">{value || "—"}</dd>
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
      className="control"
    />
  );
}
