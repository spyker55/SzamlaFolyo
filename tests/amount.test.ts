import { describe, expect, it } from "vitest";
import { formatAmountHu, parseAmountHu } from "@/lib/format/amount";

// The non-breaking space Intl uses as the Hungarian grouping separator.
const NBSP = " ";

function value(raw: string): number | null {
  const parsed = parseAmountHu(raw);
  if (!parsed.ok) throw new Error(`expected "${raw}" to parse`);
  return parsed.value;
}

describe("parseAmountHu", () => {
  it("reads the Hungarian format the UI itself renders", () => {
    expect(value(`1${NBSP}612${NBSP}900,25`)).toBe(1612900.25);
    expect(value("1 612 900")).toBe(1612900);
    expect(value("256,5")).toBe(256.5);
  });

  it("reads dots used as thousand separators", () => {
    // "1.612.900" can only be grouping — a number has one decimal mark.
    expect(value("1.612.900")).toBe(1612900);
    expect(value("1.612.900,25")).toBe(1612900.25);
  });

  it("reads the machine format the extraction model emits", () => {
    expect(value("256.5")).toBe(256.5);
    expect(value("1612900")).toBe(1612900);
  });

  it("reads a single dot before three digits as thousands, Hungarian style", () => {
    expect(value("100.000")).toBe(100000);
    expect(value("1.000")).toBe(1000);
    // A leading zero rules grouping out — nobody writes "0.500" for 500.
    expect(value("0.500")).toBe(0.5);
    // Anything but exactly three trailing digits is a decimal.
    expect(value("1.5")).toBe(1.5);
    expect(value("1.2346")).toBe(1.2346);
  });

  it("reads the English format when both separators appear", () => {
    expect(value("1,612,900.25")).toBe(1612900.25);
  });

  it("treats repeated commas as grouping", () => {
    expect(value("1,612,900")).toBe(1612900);
  });

  it("treats an empty field as a valid absent amount", () => {
    expect(value("")).toBeNull();
    expect(value("   ")).toBeNull();
  });

  it("rejects text instead of silently reporting no amount", () => {
    // The old parser returned null here, which stored NULL over the user's
    // input without a word.
    for (const raw of ["abc", "12 Ft", "1,2,3.4.5", "-", ",", "1..2", "12-34"]) {
      expect(parseAmountHu(raw).ok, raw).toBe(false);
    }
  });

  it("rounds to the NUMERIC(18,4) scale the column stores", () => {
    expect(value("1,23456")).toBe(1.2346);
  });

  it("keeps a negative amount", () => {
    expect(value("-1 200,50")).toBe(-1200.5);
  });
});

describe("formatAmountHu", () => {
  it("renders Hungarian grouping and a decimal comma", () => {
    expect(formatAmountHu(1612900.25)).toBe(`1${NBSP}612${NBSP}900,25`);
    expect(formatAmountHu(256.5)).toBe("256,5");
  });

  it("does not pad whole amounts with decimals", () => {
    expect(formatAmountHu(1612900)).toBe(`1${NBSP}612${NBSP}900`);
  });

  it("follows the Hungarian rule of leaving four-digit numbers unbroken", () => {
    expect(formatAmountHu(1207)).toBe("1207");
    expect(formatAmountHu(12345)).toBe(`12${NBSP}345`);
  });

  it("normalises what the extraction model produced", () => {
    expect(formatAmountHu("256.5")).toBe("256,5");
    expect(formatAmountHu("1612900")).toBe(`1${NBSP}612${NBSP}900`);
  });

  it("round-trips a four-digit amount, which carries no grouping to lose", () => {
    expect(value(formatAmountHu(1207))).toBe(1207);
  });

  it("renders an empty field as empty", () => {
    expect(formatAmountHu("")).toBe("");
    expect(formatAmountHu(null)).toBe("");
    expect(formatAmountHu(undefined)).toBe("");
  });

  it("hands back text it cannot read instead of destroying it", () => {
    expect(formatAmountHu("kb. 1000")).toBe("kb. 1000");
  });

  it("round-trips its own output", () => {
    for (const n of [0, 1207, 256.5, 1612900.25, -1200.5, 1.2346]) {
      expect(value(formatAmountHu(n))).toBe(n);
    }
  });
});
