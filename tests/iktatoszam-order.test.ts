import { describe, expect, it } from "vitest";
import { compareIktatoszamDesc, type IktatoszamParts } from "@/lib/iktatas/order";

function order(rows: (IktatoszamParts & { label: string })[]): string[] {
  return [...rows].sort(compareIktatoszamDesc).map((r) => r.label);
}

const row = (ev: number | null, foszam: number | null, alszam: number | null, label: string) => ({
  ev,
  foszam,
  alszam,
  label,
});

describe("iktatokonyv ordering", () => {
  it("puts 10 above 9, which text ordering does not", () => {
    expect(
      order([
        row(2026, 9, 1, "IKT/9-1/2026"),
        row(2026, 100, 1, "IKT/100-1/2026"),
        row(2026, 1, 1, "IKT/1-1/2026"),
        row(2026, 11, 1, "IKT/11-1/2026"),
        row(2026, 10, 1, "IKT/10-1/2026"),
        row(2026, 2, 1, "IKT/2-1/2026"),
      ])
    ).toEqual([
      "IKT/100-1/2026",
      "IKT/11-1/2026",
      "IKT/10-1/2026",
      "IKT/9-1/2026",
      "IKT/2-1/2026",
      "IKT/1-1/2026",
    ]);
  });

  it("orders by year before foszam", () => {
    expect(
      order([
        row(2025, 900, 1, "tavalyi nagy foszam"),
        row(2026, 1, 1, "idei elso"),
      ])
    ).toEqual(["idei elso", "tavalyi nagy foszam"]);
  });

  it("orders alszams within the same foszam", () => {
    expect(
      order([row(2026, 4, 1, "4-1"), row(2026, 4, 3, "4-3"), row(2026, 4, 2, "4-2")])
    ).toEqual(["4-3", "4-2", "4-1"]);
  });

  it("sorts a row with no ugy last instead of to the top", () => {
    expect(
      order([row(null, null, null, "nincs ugy"), row(2026, 1, 1, "IKT/1-1/2026")])
    ).toEqual(["IKT/1-1/2026", "nincs ugy"]);
  });
});
