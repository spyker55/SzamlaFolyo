import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { ExportClient } from "@/components/export/ExportClient";
import { accountingTotals, isAccountingDocument } from "@/lib/export/csv";
import { fetchExportItems, MAX_EXPORT_ROWS } from "@/lib/export/query";
import {
  currentMonthInBudapest,
  isDateBasis,
  monthLabel,
  monthRange,
  recentMonths,
  type DateBasis,
} from "@/lib/export/period";
import { docKindLabel } from "@/lib/domain/doc-kind";
import { PageHeader } from "@/components/ui/page";

export default async function ExportPage({
  searchParams,
}: {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
}) {
  await requireMembership();
  const params = await searchParams;

  const monthParam = typeof params.honap === "string" ? params.honap : "";
  const month = monthRange(monthParam) ? monthParam : currentMonthInBudapest();
  const range = monthRange(month)!;

  const basisParam = typeof params.alap === "string" ? params.alap : "";
  const basis: DateBasis = isDateBasis(basisParam) ? basisParam : "erkezett";

  const directionParam = typeof params.irany === "string" ? params.irany : "";
  const direction =
    directionParam === "bejovo" || directionParam === "kimeno" ? directionParam : null;

  const supabase = await createSupabaseServerClient();
  const items = await fetchExportItems(supabase, { ...range, basis, direction });

  const totals = accountingTotals(items);
  const withoutFile = items.filter((i) => !i.file).length;
  const ervenytelen = items.filter((i) => i.ervenytelen).length;
  const nemKonyvelendo = items.filter((i) => !i.ervenytelen && !isAccountingDocument(i)).length;

  // What is in the period, by type — a bookkeeper checks this against what
  // they expected before downloading anything.
  const byKind = new Map<string, number>();
  for (const i of items) {
    const key = i.docKind ?? "";
    byKind.set(key, (byKind.get(key) ?? 0) + 1);
  }
  const kinds = [...byKind.entries()]
    .map(([kind, count]) => ({ label: kind ? docKindLabel(kind) : "Nincs típus", count }))
    .sort((a, b) => b.count - a.count);

  // The months offered go back far enough to redo a closed quarter.
  const months = recentMonths(15).map((m) => ({ value: m, label: monthLabel(m) }));
  if (!months.some((m) => m.value === month)) {
    months.unshift({ value: month, label: monthLabel(month) });
  }

  return (
    <div>
      <PageHeader
        title="Könyvelői export"
        description="Egy hónap iratai táblázatban, vagy táblázat és a fájlok egy ZIP-ben — abban a formában, ahogy a könyvelő kéri."
      />
      <ExportClient
        month={month}
        months={months}
        basis={basis}
        direction={direction}
        from={range.from}
        to={range.to}
        count={items.length}
        limit={MAX_EXPORT_ROWS}
        totals={totals}
        kinds={kinds}
        withoutFile={withoutFile}
        ervenytelen={ervenytelen}
        nemKonyvelendo={nemKonyvelendo}
      />
    </div>
  );
}
