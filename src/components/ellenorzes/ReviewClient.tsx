"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState, useTransition } from "react";
import { iktat, type IktatValues } from "@/lib/iktatas/actions";
import { formatAmountHu, parseAmountHu } from "@/lib/format/amount";
import { DOC_KIND_OPTIONS } from "@/lib/domain/doc-kind";
import { REVIEW_THRESHOLD } from "@/lib/extraction/confidence";
import type { UgySuggestion } from "@/lib/iktatas/ugy-suggest";

export type ReviewData = {
  documentId: string;
  fileUrl: string | null;
  fileMimeType: string | null;
  fileName: string | null;
  confidence: Record<string, number>;
  tobbIratGyanu: boolean;
  // Deterministic matches, strongest first; may be empty.
  ugySuggestions: UgySuggestion[];
  // Every open ügy, so the reviewer can file anywhere the suggestion missed.
  ugyOptions: { id: string; label: string }[];
  initial: Record<string, string>;
};

const DIRECTION_OPTIONS = [
  { value: "bejovo", label: "Bejövő" },
  { value: "kimeno", label: "Kimenő" },
  { value: "belso", label: "Belső" },
];

export function ReviewClient({ data }: { data: ReviewData }) {
  const router = useRouter();
  const [values, setValues] = useState<Record<string, string>>(data.initial);
  const [error, setError] = useState<string | null>(null);
  // "" = open a new ügy. A suggestion is never preselected: accepting one
  // stamps a permanent iktatószám, so it takes a deliberate click.
  const [ugyId, setUgyId] = useState("");
  const [pending, startTransition] = useTransition();
  const formRef = useRef<HTMLDivElement>(null);
  const firstLowConfRef = useRef<HTMLInputElement>(null);

  const set = (field: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setValues((v) => ({ ...v, [field]: e.target.value }));

  const lowConfidence = useCallback(
    (field: string) => {
      const c = data.confidence[field];
      return c !== undefined && c < REVIEW_THRESHOLD;
    },
    [data.confidence]
  );

  const amounts = useMemo(
    () => ({
      net: parseAmountHu(values.net_amount ?? ""),
      vat: parseAmountHu(values.vat_amount ?? ""),
      gross: parseAmountHu(values.gross_amount ?? ""),
    }),
    [values.net_amount, values.vat_amount, values.gross_amount]
  );

  const { net, vat, gross } = amounts;
  const amountMismatch =
    net.ok &&
    vat.ok &&
    gross.ok &&
    net.value !== null &&
    vat.value !== null &&
    gross.value !== null &&
    Math.abs(net.value + vat.value - gross.value) > 1;

  // Re-format on blur rather than while typing, so the caret is never moved
  // out from under the user — and so they see how the value was read before
  // it is stored.
  const normalizeAmount = (field: string) => () =>
    setValues((v) => ({ ...v, [field]: formatAmountHu(v[field] ?? "") }));

  const submit = useCallback(() => {
    if (pending) return;
    setError(null);

    if (!values.direction || !values.doc_kind) {
      setError("Az irány és az irat fajtája kötelező.");
      return;
    }

    // An amount that cannot be read must never be stored as "no amount".
    if (!net.ok || !vat.ok || !gross.ok) {
      const bad = [!net.ok && "Nettó", !vat.ok && "ÁFA", !gross.ok && "Bruttó"].filter(Boolean);
      setError(
        `Nem értelmezhető összeg: ${bad.join(", ")}. Magyar formátumban, például: 1 612 900,25`
      );
      return;
    }

    const payload: IktatValues = {
      partner_name: values.partner_name || null,
      partner_tax_number: values.partner_tax_number || null,
      targy: values.targy || null,
      irat_szama: values.irat_szama || null,
      erkezett_at: values.erkezett_at || null,
      issue_date: values.issue_date || null,
      due_date: values.due_date || null,
      direction: values.direction,
      doc_kind: values.doc_kind,
      melleklet_db: values.melleklet_db === "" ? 0 : Number(values.melleklet_db),
      net_amount: net.value,
      vat_amount: vat.value,
      gross_amount: gross.value,
      currency: values.currency || null,
      kezelesi_feljegyzes: values.kezelesi_feljegyzes || null,
      irattari_jel: values.irattari_jel || null,
    };

    startTransition(async () => {
      const result = await iktat(data.documentId, payload, ugyId || null);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      if (result.nextDocumentId) {
        router.push(`/ellenorzes/${result.nextDocumentId}`);
      } else {
        router.push("/iktatokonyv");
      }
    });
  }, [data.documentId, pending, router, values, net, vat, gross, ugyId]);

  // Enter = iktatás és ugrás a következőre; Esc = vissza a Beérkezőbe.
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        router.push("/inbox");
        return;
      }
      if (e.key === "Enter" && !(e.target instanceof HTMLTextAreaElement)) {
        e.preventDefault();
        submit();
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [router, submit]);

  useEffect(() => {
    firstLowConfRef.current?.focus();
  }, []);

  const fieldClass = (field: string) =>
    `control ${lowConfidence(field) ? "border-amber-400 bg-amber-50" : ""}`;

  const label = (field: string, text: string) => (
    <label htmlFor={field} className="flabel flex items-center gap-1">
      {text}
      {lowConfidence(field) && (
        <span title="Alacsony konfidencia — ellenőrizd!" className="text-amber-600">
          ⚠
        </span>
      )}
    </label>
  );

  return (
    <div className="flex h-[calc(100vh-7rem)] min-h-[34rem] flex-col gap-4 lg:flex-row">
      {/* Left: the original document */}
      <div className="card min-h-64 flex-1 overflow-hidden bg-slate-100">
        {data.fileUrl ? (
          data.fileMimeType === "application/pdf" ? (
            <iframe src={data.fileUrl} title={data.fileName ?? "Irat"} className="h-full w-full" />
          ) : (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={data.fileUrl}
              alt={data.fileName ?? "Irat"}
              className="h-full w-full object-contain"
            />
          )
        ) : (
          <div className="flex h-full items-center justify-center text-slate-400">
            A fájl nem tölthető be.
          </div>
        )}
      </div>

      {/* Right: extracted fields */}
      <div
        ref={formRef}
        className="card card-pad w-full shrink-0 overflow-y-auto lg:w-[30rem]"
      >
        <div className="mb-4 flex items-center justify-between">
          <h1 className="text-lg font-semibold text-slate-900">Ellenőrzés</h1>
          <span className="note">Enter = iktatás · Esc = vissza</span>
        </div>

        {data.tobbIratGyanu && (
          <p className="alert alert-warn mb-3 text-xs">
            Ez a fájl több különálló iratot tartalmazhat. Iktatás előtt érdemes szétválasztani
            és külön feltölteni.
          </p>
        )}

        {data.ugySuggestions.length > 0 && (
          <div className="alert alert-info mb-3 p-2">
            <p className="px-1 text-xs font-semibold">Lehet, hogy meglévő ügyhöz tartozik:</p>
            <ul className="mt-1 space-y-1">
              {data.ugySuggestions.map((s) => (
                <li key={s.ugyId}>
                  <button
                    type="button"
                    onClick={() => setUgyId(ugyId === s.ugyId ? "" : s.ugyId)}
                    className={`w-full rounded-lg border px-2 py-1.5 text-left text-xs transition-colors ${
                      ugyId === s.ugyId
                        ? "border-blue-500 bg-white font-medium text-blue-900"
                        : "border-transparent text-blue-800 hover:bg-blue-100"
                    }`}
                  >
                    {ugyId === s.ugyId ? "✓ " : ""}
                    {s.label}
                    {/* Always say why, so the reviewer can check the claim. */}
                    <span className="block text-[11px] font-normal text-blue-600">
                      {s.reason}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="mb-4">
          <label htmlFor="ugy_id" className="flabel">
            Ügy
          </label>
          <select
            id="ugy_id"
            value={ugyId}
            onChange={(e) => setUgyId(e.target.value)}
            className="control"
          >
            <option value="">Új ügy nyitása — új főszám</option>
            {data.ugyOptions.map((o) => (
              <option key={o.id} value={o.id}>
                {o.label}
              </option>
            ))}
          </select>
          <p className="note mt-1">
            {ugyId
              ? "Az irat a választott ügy következő alszámát kapja."
              : "Az irat új főszámot kap, saját ügyet nyitva."}
          </p>
        </div>

        <div className="space-y-3">
          <div>
            {label("partner_name", "Beküldő / partner neve")}
            <input
              id="partner_name"
              ref={lowConfidence("partner_name") ? firstLowConfRef : undefined}
              value={values.partner_name ?? ""}
              onChange={set("partner_name")}
              className={fieldClass("partner_name")}
            />
          </div>
          <div>
            {label("partner_tax_number", "Partner adószáma")}
            <input
              id="partner_tax_number"
              value={values.partner_tax_number ?? ""}
              onChange={set("partner_tax_number")}
              placeholder="12345678-2-42"
              className={fieldClass("partner_tax_number")}
            />
          </div>
          <div>
            {label("targy", "Tárgy")}
            <input
              id="targy"
              value={values.targy ?? ""}
              onChange={set("targy")}
              className={fieldClass("targy")}
            />
          </div>
          <div>
            {label("irat_szama", "Irat száma")}
            <input
              id="irat_szama"
              value={values.irat_szama ?? ""}
              onChange={set("irat_szama")}
              className={fieldClass("irat_szama")}
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              {label("direction", "Irány")}
              <select
                id="direction"
                value={values.direction ?? ""}
                onChange={set("direction")}
                className={fieldClass("direction")}
              >
                <option value="">— válassz —</option>
                {DIRECTION_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              {label("doc_kind", "Irat fajtája")}
              <select
                id="doc_kind"
                value={values.doc_kind ?? ""}
                onChange={set("doc_kind")}
                className={fieldClass("doc_kind")}
              >
                <option value="">— válassz —</option>
                {DOC_KIND_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {/* Two columns, not three: a native date control renders the full
              "2026. 07. 30." plus its picker icon and needs ~165px — a third
              of this panel clipped it. */}
          <div className="grid grid-cols-2 gap-3">
            <div className="min-w-0">
              {label("erkezett_at", "Érkezett")}
              <input
                id="erkezett_at"
                type="date"
                value={values.erkezett_at ?? ""}
                onChange={set("erkezett_at")}
                className={fieldClass("erkezett_at")}
              />
            </div>
            <div className="min-w-0">
              {label("issue_date", "Kelt")}
              <input
                id="issue_date"
                type="date"
                value={values.issue_date ?? ""}
                onChange={set("issue_date")}
                className={fieldClass("issue_date")}
              />
            </div>
            <div className="min-w-0">
              {label("due_date", "Határidő")}
              <input
                id="due_date"
                type="date"
                value={values.due_date ?? ""}
                onChange={set("due_date")}
                className={fieldClass("due_date")}
              />
            </div>
          </div>

          {/* Currency is three characters; the amounts need the room instead
              so a seven-digit HUF gross stays readable. */}
          <div className="grid grid-cols-[1fr_1fr_1fr_4.5rem] gap-3">
            <div className="min-w-0">
              {label("net_amount", "Nettó")}
              <input
                id="net_amount"
                inputMode="decimal"
                value={values.net_amount ?? ""}
                onChange={set("net_amount")}
                onBlur={normalizeAmount("net_amount")}
                className={fieldClass("net_amount")}
              />
            </div>
            <div className="min-w-0">
              {label("vat_amount", "ÁFA")}
              <input
                id="vat_amount"
                inputMode="decimal"
                value={values.vat_amount ?? ""}
                onChange={set("vat_amount")}
                onBlur={normalizeAmount("vat_amount")}
                className={fieldClass("vat_amount")}
              />
            </div>
            <div className="min-w-0">
              {label("gross_amount", "Bruttó")}
              <input
                id="gross_amount"
                inputMode="decimal"
                value={values.gross_amount ?? ""}
                onChange={set("gross_amount")}
                onBlur={normalizeAmount("gross_amount")}
                className={fieldClass("gross_amount")}
              />
            </div>
            <div className="min-w-0">
              {label("currency", "Pénznem")}
              <input
                id="currency"
                value={values.currency ?? ""}
                onChange={set("currency")}
                placeholder="HUF"
                maxLength={3}
                className={fieldClass("currency")}
              />
            </div>
          </div>

          {amountMismatch && (
            <p className="alert alert-warn text-xs">
              A nettó + ÁFA nem egyezik a bruttóval. Fordított adózásnál vagy alanyi
              adómentességnél az ÁFA 0 — akkor ez rendben van.
            </p>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div>
              {label("melleklet_db", "Mellékletek (db)")}
              <input
                id="melleklet_db"
                type="number"
                min={0}
                value={values.melleklet_db ?? "0"}
                onChange={set("melleklet_db")}
                className={fieldClass("melleklet_db")}
              />
            </div>
            <div>
              {label("irattari_jel", "Irattári jel")}
              <input
                id="irattari_jel"
                value={values.irattari_jel ?? ""}
                onChange={set("irattari_jel")}
                className={fieldClass("irattari_jel")}
              />
            </div>
          </div>

          <div>
            {label("kezelesi_feljegyzes", "Kezelési feljegyzések")}
            <textarea
              id="kezelesi_feljegyzes"
              rows={2}
              value={values.kezelesi_feljegyzes ?? ""}
              onChange={set("kezelesi_feljegyzes")}
              className={fieldClass("kezelesi_feljegyzes")}
            />
          </div>

          {error && (
            <p className="alert alert-error" role="alert">
              {error}
            </p>
          )}

          <button
            type="button"
            onClick={submit}
            disabled={pending}
            className="btn btn-primary w-full py-2"
          >
            {pending ? "Iktatás…" : "Iktatás (Enter)"}
          </button>
        </div>
      </div>
    </div>
  );
}
