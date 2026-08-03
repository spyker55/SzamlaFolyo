import Link from "next/link";
import { formatAmountHu } from "@/lib/format/amount";
import { formatTaxNumber } from "@/lib/partner/identity";
import { DOC_KIND_LABEL, type DocKind } from "@/lib/domain/doc-kind";
import {
  KIHAGYAS_OK_LABEL,
  navGross,
  type Egyeztetes,
  type KihagyasOk,
  type NavSide,
  type RegisterSide,
} from "@/lib/nav/reconcile";

const OPERATION_LABEL: Record<string, string> = {
  CREATE: "Számla",
  MODIFY: "Módosító",
  STORNO: "Sztornó",
};

function penz(value: number | null, currency: string | null): string {
  if (value === null) return "—";
  return `${formatAmountHu(value)}${currency ? ` ${currency}` : ""}`;
}

function iratNeve(irat: RegisterSide): string {
  return irat.iratSzama?.trim() || "(nincs számlaszám)";
}

function navPartner(row: NavSide): string {
  const tax = row.partnerTaxCore ? formatTaxNumber(row.partnerTaxCore) : null;
  return [row.partnerName ?? "—", tax].filter(Boolean).join(" · ");
}

function Szakasz({
  cim,
  leiras,
  hangsuly,
  darab,
  children,
}: {
  cim: string;
  leiras: string;
  hangsuly: "red" | "amber" | "slate";
  darab: number;
  children: React.ReactNode;
}) {
  if (darab === 0) return null;
  const badge =
    hangsuly === "red" ? "badge badge-red" : hangsuly === "amber" ? "badge badge-amber" : "badge badge-slate";

  return (
    <section className="card">
      <div className="card-head">
        <div className="min-w-0">
          <h2 className="card-title">
            {cim} <span className={badge}>{darab}</span>
          </h2>
          <p className="mt-1 text-sm text-slate-500">{leiras}</p>
        </div>
      </div>
      {children}
    </section>
  );
}

function IratLink({ irat }: { irat: RegisterSide }) {
  const label = irat.iktatoszam ?? "még nincs iktatva";
  if (!irat.ugyId) return <span className="text-slate-500">{label}</span>;
  return (
    <Link href={`/ugyek/${irat.ugyId}`} className="text-blue-700 hover:underline">
      {label}
    </Link>
  );
}

export function EgyeztetesEredmeny({
  eredmeny,
  direction,
}: {
  eredmeny: Egyeztetes;
  direction: "bejovo" | "kimeno";
}) {
  const kihagyottak = new Map<KihagyasOk, RegisterSide[]>();
  for (const { irat, ok } of eredmeny.kihagyva) {
    const list = kihagyottak.get(ok);
    if (list) list.push(irat);
    else kihagyottak.set(ok, [irat]);
  }

  return (
    <div className="space-y-5">
      <Szakasz
        cim="Hiányzik az iktatóból"
        hangsuly="red"
        darab={eredmeny.hianyzik.length}
        leiras={
          direction === "bejovo"
            ? "A szállító bejelentette a NAV-nak, de nálunk nincs meg. Ezeket a számlákat kérd be — a levonási jog rajtuk múlik."
            : "A számlázó program bejelentette a NAV-nak, de az iktatóban nincs meg."
        }
      >
        <div className="table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Számlaszám</th>
                <th className="th">{direction === "bejovo" ? "Szállító" : "Vevő"}</th>
                <th className="th">Kelt</th>
                <th className="th text-right">Összeg</th>
                <th className="th">Típus</th>
                <th className="th">Bejelentve</th>
              </tr>
            </thead>
            <tbody>
              {eredmeny.hianyzik.map((row) => (
                <tr key={row.id} className="trow">
                  <td className="td whitespace-nowrap font-medium text-slate-900">{row.invoiceNumber}</td>
                  <td className="td whitespace-nowrap">{navPartner(row)}</td>
                  <td className="td whitespace-nowrap">{row.issueDate ?? "—"}</td>
                  <td className="td whitespace-nowrap text-right">
                    {penz(navGross(row), row.currency)}
                  </td>
                  <td className="td whitespace-nowrap">
                    {row.invoiceOperation
                      ? (OPERATION_LABEL[row.invoiceOperation] ?? row.invoiceOperation)
                      : "—"}
                  </td>
                  <td className="td whitespace-nowrap text-slate-500">
                    {row.insDate?.slice(0, 10) ?? "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Szakasz>

      <Szakasz
        cim="Valószínű egyezés, eltérő számlaszám"
        hangsuly="amber"
        darab={eredmeny.valoszinu.length}
        leiras="Ugyanaz a partner, ugyanaz a kelt és ugyanaz az összeg, de a számlaszám nem egyezik. Szinte mindig elgépelés az egyik oldalon — érdemes az iktatott számlaszámot javítani."
      >
        <div className="table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Iktatva</th>
                <th className="th">Nálunk</th>
                <th className="th">A NAV szerint</th>
                <th className="th">Partner</th>
                <th className="th text-right">Összeg</th>
              </tr>
            </thead>
            <tbody>
              {eredmeny.valoszinu.map(({ nav, irat }) => (
                <tr key={irat.id} className="trow">
                  <td className="td whitespace-nowrap">
                    <IratLink irat={irat} />
                  </td>
                  <td className="td whitespace-nowrap font-medium text-slate-900">{iratNeve(irat)}</td>
                  <td className="td whitespace-nowrap font-medium text-slate-900">{nav.invoiceNumber}</td>
                  <td className="td whitespace-nowrap">{navPartner(nav)}</td>
                  <td className="td whitespace-nowrap text-right">
                    {penz(irat.grossAmount, irat.currency)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Szakasz>

      <Szakasz
        cim="Nálunk megvan, a NAV nem tud róla"
        hangsuly="amber"
        darab={eredmeny.nincsNavnal.length}
        leiras={
          direction === "bejovo"
            ? "A szállító nem jelentette be. Ez az ő kötelezettsége — de a számla addig kockázatos, amíg nincs a NAV rendszerében."
            : "A saját számlázó programod nem jelentette be. Ez a cég adatszolgáltatási kötelezettsége, érdemes azonnal utánanézni."
        }
      >
        <div className="table-scroll">
          <table className="tbl">
            <thead className="thead">
              <tr>
                <th className="th">Iktatva</th>
                <th className="th">Számlaszám</th>
                <th className="th">Partner</th>
                <th className="th">Kelt</th>
                <th className="th text-right">Összeg</th>
              </tr>
            </thead>
            <tbody>
              {eredmeny.nincsNavnal.map((irat) => (
                <tr key={irat.id} className="trow">
                  <td className="td whitespace-nowrap">
                    <IratLink irat={irat} />
                  </td>
                  <td className="td whitespace-nowrap font-medium text-slate-900">{iratNeve(irat)}</td>
                  <td className="td whitespace-nowrap">
                    {[irat.partnerName ?? "—", irat.partnerTaxCore ? formatTaxNumber(irat.partnerTaxCore) : null]
                      .filter(Boolean)
                      .join(" · ")}
                  </td>
                  <td className="td whitespace-nowrap">{irat.issueDate ?? "—"}</td>
                  <td className="td whitespace-nowrap text-right">
                    {penz(irat.grossAmount, irat.currency)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Szakasz>

      {/* One closing card for everything the lists above did not flag. Three
          separate boxes saying "nothing to do here" read as three findings. */}
      <div className="card card-pad space-y-2 text-sm">
        <p className="text-slate-600">
          <strong>{eredmeny.egyezik.length}</strong> számla mindkét helyen megvan.
        </p>

        {eredmeny.friss.length > 0 && (
          <p className="text-slate-500">
            {eredmeny.friss.length} irat túl friss ahhoz, hogy már a NAV-nál legyen — a
            bejelentésre a kiállítónak a következő munkanap végéig van ideje. Ezeket nem
            hiányolja a lista.
          </p>
        )}

        {eredmeny.kihagyva.length > 0 && (
          <details>
            <summary className="cursor-pointer text-slate-600">
              {eredmeny.kihagyva.length} irat nem összehasonlítható — miért?
            </summary>
            <ul className="mt-2 space-y-1 text-slate-500">
              {[...kihagyottak.entries()].map(([ok, iratok]) => (
                <li key={ok}>
                  <span className="font-medium text-slate-700">{iratok.length} db</span> —{" "}
                  {KIHAGYAS_OK_LABEL[ok]}
                  {ok === "nem_szamla" && (
                    <span className="text-slate-400">
                      {" "}
                      ({[...new Set(iratok.map((i) => i.docKind))]
                        .filter(Boolean)
                        .map((k) => DOC_KIND_LABEL[k as DocKind] ?? k)
                        .join(", ")})
                    </span>
                  )}
                </li>
              ))}
            </ul>
          </details>
        )}
      </div>
    </div>
  );
}
