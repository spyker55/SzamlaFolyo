import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  acceptsNewIrat,
  canTransition,
  isEditable,
  isUgyStatus,
  nextStatuses,
  transitionLabel,
  TRANSITION_LABEL,
  UGY_STATUSES,
  UGY_STATUS_LABEL,
  UGY_TRANSITIONS,
  type UgyStatus,
} from "@/lib/ugy/status";
import { compareUgyForList, deadlineState, deadlineText } from "@/lib/ugy/order";

const MIGRATIONS_DIR = join(process.cwd(), "supabase", "migrations");

// The database is the authority: app.protect_ugy() decides what is allowed.
// This rebuilds that decision from the migration so the screen can never
// offer a button the database will refuse.
function transitionsFromMigration(): string[] {
  const files = readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith(".sql")).sort();
  const found: string[] = [];
  for (const file of files) {
    const sql = readFileSync(join(MIGRATIONS_DIR, file), "utf8");
    const block = /v_allowed\s+constant\s+text\[\]\s*:=\s*array\[([^\]]*)\]/i.exec(sql);
    if (!block) continue;
    for (const m of block[1].matchAll(/'([a-z_]+)->([a-z_]+)'/g)) {
      found.push(`${m[1]}->${m[2]}`);
    }
  }
  return found;
}

describe("ugy status machine", () => {
  it("matches the transitions the migration enforces", () => {
    const fromSql = transitionsFromMigration();
    expect(fromSql.length).toBeGreaterThan(0);
    expect([...fromSql].sort()).toEqual([...UGY_TRANSITIONS].sort());
  });

  it("only ever names statuses that exist", () => {
    for (const t of UGY_TRANSITIONS) {
      const [from, to] = t.split("->");
      expect(isUgyStatus(from)).toBe(true);
      expect(isUgyStatus(to)).toBe(true);
    }
  });

  it("labels every status and every transition", () => {
    for (const s of UGY_STATUSES) expect(UGY_STATUS_LABEL[s]).toBeTruthy();
    for (const t of UGY_TRANSITIONS) expect(TRANSITION_LABEL[t]).toBeTruthy();
  });

  it("never allows a jump straight into the archive", () => {
    // An ugy is closed first — that is the clerical workflow, and the
    // database refuses the shortcut.
    expect(canTransition("folyamatban", "irattarazott")).toBe(false);
    expect(canTransition("felfuggesztve", "irattarazott")).toBe(false);
    expect(canTransition("lezart", "irattarazott")).toBe(true);
  });

  it("lets an archived ugy out only back to lezart", () => {
    expect(nextStatuses("irattarazott")).toEqual(["lezart"]);
  });

  it("never offers a transition to itself", () => {
    for (const s of UGY_STATUSES) expect(nextStatuses(s)).not.toContain(s);
  });

  it("names the same target differently depending on where you came from", () => {
    // The button says "Lezárás" from an open ugy but "Kivétel az irattárból"
    // from the archive, even though both land on 'lezart'.
    expect(transitionLabel("folyamatban", "lezart")).toBe("Lezárás");
    expect(transitionLabel("irattarazott", "lezart")).toBe("Kivétel az irattárból");
  });

  it("agrees with iktat_document about which ugy takes a new irat", () => {
    const acceptsInSql = new Set<UgyStatus>(["folyamatban", "felfuggesztve"]);
    for (const s of UGY_STATUSES) {
      expect(acceptsNewIrat(s)).toBe(acceptsInSql.has(s));
    }
  });

  it("freezes an archived ugy and nothing else", () => {
    for (const s of UGY_STATUSES) {
      expect(isEditable(s)).toBe(s !== "irattarazott");
    }
  });
});

describe("ugy list order", () => {
  const u = (hatarido: string | null, foszam: number, ev = 2026) => ({ hatarido, foszam, ev });

  it("puts the nearest deadline first", () => {
    const rows = [u("2026-08-11", 2), u(null, 9), u("2026-05-09", 6), u("2026-07-17", 1)];
    expect(rows.sort(compareUgyForList).map((r) => r.foszam)).toEqual([6, 1, 2, 9]);
  });

  it("sinks the ugyek with no deadline, newest of them first", () => {
    const rows = [u(null, 3), u(null, 7), u("2026-09-01", 1)];
    expect(rows.sort(compareUgyForList).map((r) => r.foszam)).toEqual([1, 7, 3]);
  });

  it("orders across years by year first", () => {
    const rows = [u(null, 9, 2025), u(null, 1, 2026)];
    expect(rows.sort(compareUgyForList).map((r) => r.ev)).toEqual([2026, 2025]);
  });

  it("reads a deadline the same way the fizetesi naptar does", () => {
    expect(deadlineState("2026-07-30", "2026-07-31")).toBe("lejart");
    expect(deadlineState("2026-07-31", "2026-07-31")).toBe("ma");
    expect(deadlineState("2026-08-07", "2026-07-31")).toBe("kozeli");
    expect(deadlineState("2026-08-08", "2026-07-31")).toBe("tavoli");
    expect(deadlineState(null, "2026-07-31")).toBe("nincs");
  });

  it("counts days across a DST change without drifting", () => {
    // Hungary moves the clock on 2026-10-25; an ugy due the day after must
    // not read as two days out.
    expect(deadlineText("2026-10-26", "2026-10-25")).toBe("1 nap múlva");
  });
});

describe("iktat_document still refuses closed and archived ugyek", () => {
  it("says so in the migration", () => {
    const files = readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith(".sql")).sort();
    const sql = files.map((f) => readFileSync(join(MIGRATIONS_DIR, f), "utf8")).join("\n");
    // If this check ever moves or changes shape, acceptsNewIrat() above is
    // guessing rather than mirroring, and this test should fail loudly.
    expect(sql).toMatch(/v_ugy\.status\s+in\s*\(\s*'lezart'\s*,\s*'irattarazott'\s*\)/);
  });
});
