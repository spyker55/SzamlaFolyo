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
  // app.protect_ugy() is re-created by later migrations, so only the last
  // definition is the one running. Reading every block and concatenating them
  // would compare the union of every version this function ever had.
  let latest: string | null = null;
  for (const file of files) {
    const sql = readFileSync(join(MIGRATIONS_DIR, file), "utf8");
    const block = /v_allowed\s+constant\s+text\[\]\s*:=\s*array\[([^\]]*)\]/i.exec(sql);
    if (block) latest = block[1];
  }
  if (latest === null) return [];
  return [...latest.matchAll(/'([a-z_]+)->([a-z_]+)'/g)].map((m) => `${m[1]}->${m[2]}`);
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
  const u = (hatarido: string | null, foszam: number, ev = 2026, status = "folyamatban") => ({
    hatarido,
    foszam,
    ev,
    status,
  });

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

  it("sinks a finished ugy below every running one, however old its deadline", () => {
    // The real case: IKT/6/2026 was archived in May with a deadline months
    // past, and being the oldest date put it at the very top of the list —
    // above the work that is actually open.
    const rows = [
      u("2026-05-09", 6, 2026, "irattarazott"),
      u("2026-07-25", 4, 2026, "lezart"),
      u("2026-08-11", 2, 2026, "folyamatban"),
      u(null, 3, 2026, "felfuggesztve"),
    ];
    expect(rows.sort(compareUgyForList).map((r) => r.foszam)).toEqual([2, 3, 6, 4]);
  });

  it("still orders the finished ones among themselves by deadline", () => {
    const rows = [
      u("2026-07-25", 4, 2026, "lezart"),
      u("2026-05-09", 6, 2026, "irattarazott"),
    ];
    expect(rows.sort(compareUgyForList).map((r) => r.foszam)).toEqual([6, 4]);
  });

  it("reads a deadline the same way the fizetesi naptar does", () => {
    expect(deadlineState("2026-07-30", "2026-07-31", "folyamatban")).toBe("lejart");
    expect(deadlineState("2026-07-31", "2026-07-31", "folyamatban")).toBe("ma");
    expect(deadlineState("2026-08-07", "2026-07-31", "folyamatban")).toBe("kozeli");
    expect(deadlineState("2026-08-08", "2026-07-31", "folyamatban")).toBe("tavoli");
    expect(deadlineState(null, "2026-07-31", "folyamatban")).toBe("nincs");
    // A suspended ugy is still running: the case is on hold, the clock is not.
    expect(deadlineState("2026-07-30", "2026-07-31", "felfuggesztve")).toBe("lejart");
  });

  it("stops counting once the ugy is finished", () => {
    for (const status of ["lezart", "irattarazott"]) {
      expect(deadlineState("2026-05-09", "2026-08-01", status)).toBe("lezarult");
      expect(deadlineText("2026-05-09", "2026-08-01", status)).toBe("");
    }
    // While it is still open, it very much does count.
    expect(deadlineText("2026-05-09", "2026-08-01", "folyamatban")).toBe("84 napja lejárt");
  });

  it("says nothing under a date that is not there", () => {
    // The cell above already renders "—"; repeating it made every deadline-less
    // ugy read "— / —".
    expect(deadlineText(null, "2026-08-01", "folyamatban")).toBe("");
  });

  it("counts days across a DST change without drifting", () => {
    // Hungary moves the clock on 2026-10-25; an ugy due the day after must
    // not read as two days out.
    expect(deadlineText("2026-10-26", "2026-10-25", "folyamatban")).toBe("1 nap múlva");
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

describe("the archived ügy stays frozen except for a partner merge", () => {
  function latestProtectUgy(): string {
    const files = readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith(".sql")).sort();
    let latest = "";
    for (const file of files) {
      const sql = readFileSync(join(MIGRATIONS_DIR, file), "utf8");
      const at = sql.lastIndexOf("create or replace function app.protect_ugy()");
      if (at >= 0) latest = sql.slice(at);
    }
    return latest;
  }

  it("lets a merge move partner_id and nothing else", () => {
    // A partner merge has to reach archived ügyek: leaving one pointing at
    // the retired duplicate is the mess the merge exists to clear up. The
    // carve-out is exactly one column wide, and this is what says so.
    const sql = latestProtectUgy();
    expect(sql).toContain("v_frozen := old");
    expect(sql).toMatch(/current_setting\('app\.partner_merge', true\)/);
    expect(sql).toMatch(/v_frozen\.partner_id\s*:=\s*new\.partner_id/);
    expect(sql).toMatch(/if new is distinct from v_frozen then/);
  });

  it("still refuses to renumber an ügy, merge or no merge", () => {
    const sql = latestProtectUgy();
    expect(sql).toMatch(/new\.foszam is distinct from old\.foszam/);
    expect(sql).toContain("ugy identity is immutable");
  });
});
