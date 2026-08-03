import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  AUDIT_ACTION_LABEL,
  auditActionLabel,
  auditActorName,
  auditChangeLines,
  auditContextNote,
  auditEntityHref,
  formatAuditValue,
  type AuditEvent,
} from "@/lib/audit/labels";
import { AUDIT_PERIODS, budapestDayStart, periodSince } from "@/lib/audit/query";

const MIGRATIONS_DIR = join(process.cwd(), "supabase", "migrations");

// Every migration that writes audit entries, not just the one that introduced
// the table: the irattári terv added five actions of its own in a later file,
// and a test pinned to a single filename would have missed all five.
function auditActionsFromMigrations(): Set<string> {
  const actions = new Set<string>();
  for (const file of readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith(".sql"))) {
    const sql = readFileSync(join(MIGRATIONS_DIR, file), "utf8");
    if (!sql.includes("app.audit_write(")) continue;
    for (const m of sql.matchAll(
      /'((?:document|ugy|partner|tag|ceg|export|irattar)\.[a-z_]+)'/g
    )) {
      actions.add(m[1]);
    }
  }
  return actions;
}

function event(partial: Partial<AuditEvent>): AuditEvent {
  return {
    id: "e1",
    action: "document.modositva",
    entityType: "document",
    entityId: "d1",
    entityLabel: "IKT/1-1/2026",
    actorEmail: "anna@pelda.hu",
    actorKind: "user",
    changes: {},
    context: {},
    createdAt: "2026-08-01T09:15:00.000Z",
    ...partial,
  };
}

describe("the action vocabulary", () => {
  // The triggers decide what actions exist; this module only names them. If a
  // migration ever writes an action with no Hungarian label, the napló would
  // quietly render a raw key like 'document.valami' at the user — so the
  // authority is read back out of the SQL rather than trusted to stay in sync.
  it("has a Hungarian label for every action the migrations write", () => {
    const actions = auditActionsFromMigrations();

    expect(actions.size).toBeGreaterThan(20);
    for (const action of actions) {
      expect(AUDIT_ACTION_LABEL[action], `no Hungarian label for ${action}`).toBeTruthy();
    }
  });

  it("shows an unknown action as itself rather than hiding it", () => {
    expect(auditActionLabel("valami.uj")).toBe("valami.uj");
  });
});

describe("formatting a value", () => {
  it("renders money the Hungarian way, from the string PostgREST returns", () => {
    // hu-HU groups with a non-breaking space, the same one amount.test.ts asserts.
    expect(formatAuditValue("gross_amount", "127000.0000")).toBe("127 000");
  });

  it("translates the enums the columns hold", () => {
    expect(formatAuditValue("doc_kind", "dijbekero")).toBe("Díjbekérő");
    expect(formatAuditValue("direction", "bejovo")).toBe("Bejövő");
    expect(formatAuditValue("status", "irattarazott")).toBe("Irattárazott");
    expect(formatAuditValue("processing_status", "needs_review")).toBe("Ellenőrzésre vár");
    expect(formatAuditValue("role", "eloado")).toBe("Előadó");
  });

  it("says — for an empty value instead of leaving a gap", () => {
    expect(formatAuditValue("targy", null)).toBe("—");
    expect(formatAuditValue("targy", "")).toBe("—");
  });

  it("cuts free text so one long megjegyzés cannot fill the page", () => {
    const long = "a".repeat(400);
    const shown = formatAuditValue("note", long);
    expect(shown.length).toBeLessThan(200);
    expect(shown.endsWith("…")).toBe(true);
  });

  // Az üres őrzési idő nem hiányzó adat, hanem a legerősebb érték. "—"-ként
  // kiírva a "nem selejtezhető" ellenkezőjét mondaná.
  it("calls an empty őrzési idő what it is", () => {
    expect(formatAuditValue("orzesi_ido_ev", null)).toBe("nem selejtezhető");
    expect(formatAuditValue("orzesi_ido_ev", 8)).toBe("8");
  });

  it("keeps an unknown enum value visible", () => {
    expect(formatAuditValue("doc_kind", "valami_uj")).toBe("valami_uj");
  });
});

describe("the change lines", () => {
  it("renders a plain edit as from → to", () => {
    const lines = auditChangeLines(
      event({ changes: { targy: { from: "Régi tárgy", to: "Új tárgy" } } })
    );
    expect(lines).toEqual([
      { field: "targy", label: "Tárgy", from: "Régi tárgy", to: "Új tárgy", note: null },
    ]);
  });

  // A uuid tells the reader nothing, and a log line nobody can read is a log
  // line nobody reads.
  it("describes a foreign key instead of printing its uuid", () => {
    const [assigned] = auditChangeLines(
      event({ changes: { partner_id: { from: null, to: "8f14e45f-ceea-467a-9575-28bd1f9b1b1b" } } })
    );
    expect(assigned.note).toBe("hozzárendelve");
    expect(assigned.from).toBeNull();

    const [removed] = auditChangeLines(
      event({ changes: { partner_id: { from: "8f14e45f-ceea-467a-9575-28bd1f9b1b1b", to: null } } })
    );
    expect(removed.note).toBe("eltávolítva");

    const [moved] = auditChangeLines(
      event({
        changes: {
          ugy_id: {
            from: "8f14e45f-ceea-467a-9575-28bd1f9b1b1b",
            to: "1b9b1f9b-28bd-9575-467a-ceea8f14e45f",
          },
        },
      })
    );
    expect(moved.note).toBe("megváltozott");
  });

  // "Irattári tétel inaktiválva" fejlécű bejegyzés alatt az "Elvetve" sor a
  // saját címét cáfolná.
  it("names deleted_at after the thing it happened to", () => {
    const [line] = auditChangeLines(
      event({
        entityType: "irattari_tetel",
        action: "irattar.tetel_inaktivalva",
        changes: { deleted_at: { from: null, to: "2026-08-01T10:00:00" } },
      })
    );
    expect(line.label).toBe("Inaktiválva");

    const [partnerLine] = auditChangeLines(
      event({ entityType: "partner", changes: { deleted_at: { from: null, to: "2026-08-01" } } })
    );
    expect(partnerLine.label).toBe("Törölve");
  });

  it("skips the bookkeeping columns", () => {
    const lines = auditChangeLines(
      event({
        changes: {
          updated_at: { from: "2026-08-01", to: "2026-08-02" },
          targy: { from: "a", to: "b" },
        },
      })
    );
    expect(lines.map((l) => l.field)).toEqual(["targy"]);
  });

  it("orders the lines the same way every time", () => {
    const changes = {
      targy: { from: "a", to: "b" },
      hatarido: { from: null, to: "2026-09-01" },
      irattari_jel: { from: null, to: "U-1" },
    };
    const first = auditChangeLines(event({ changes })).map((l) => l.label);
    const second = auditChangeLines(
      event({ changes: { irattari_jel: changes.irattari_jel, targy: changes.targy, hatarido: changes.hatarido } })
    ).map((l) => l.label);
    expect(first).toEqual(second);
    expect(first).toEqual(["Határidő", "Irattári jel", "Tárgy"]);
  });
});

describe("the context line", () => {
  it("summarises an export in one sentence", () => {
    const note = auditContextNote(
      event({
        action: "export.letoltve",
        context: {
          format: "zip",
          from: "2026-07-01",
          to: "2026-07-31",
          count: 42,
          direction: "bejovo",
        },
      })
    );
    expect(note).toBe("ZIP · 2026-07-01 – 2026-07-31 · 42 irat · Bejövő");
  });

  it("shows the file's fingerprint, shortened", () => {
    const note = auditContextNote(
      event({
        action: "document.fajl_csatolva",
        context: { original_filename: "szamla.pdf", sha256: "abcdef0123456789abcdef" },
      })
    );
    expect(note).toBe("szamla.pdf · sha256 abcdef012345…");
  });

  it("names the surviving partner after a merge", () => {
    expect(
      auditContextNote(
        event({ action: "partner.osszevonva", context: { survivor_name: "Nethely Kft." } })
      )
    ).toBe("Megmaradt partner: Nethely Kft.");
  });

  it("has nothing to say when the context is empty", () => {
    expect(auditContextNote(event({ action: "document.modositva" }))).toBeNull();
  });
});

describe("actor and destination", () => {
  it("calls a service-role write what it is", () => {
    expect(auditActorName(event({ actorKind: "system", actorEmail: null }))).toBe("Rendszer");
  });

  it("keeps the e-mail recorded at the time", () => {
    expect(auditActorName(event({}))).toBe("anna@pelda.hu");
  });

  // Iratok have no screen of their own; a link to one would 404.
  it("links only where there is somewhere to go", () => {
    expect(auditEntityHref(event({ entityType: "ugy", entityId: "u1" }))).toBe("/ugyek/u1");
    expect(auditEntityHref(event({ entityType: "partner", entityId: "p1" }))).toBe("/partnerek/p1");
    expect(auditEntityHref(event({ entityType: "document" }))).toBeNull();
  });
});

describe("the period filter", () => {
  it("starts the day in Budapest, not in UTC", () => {
    // 00:30 Budapest on 2 August is still 22:30 UTC on 1 August in summer; a
    // UTC boundary would hide the half hour of work just done.
    const at = new Date("2026-08-01T22:30:00.000Z");
    expect(budapestDayStart(0, at)).toBe("2026-08-02T00:00:00+02:00");
  });

  it("moves the boundary with the clock change", () => {
    const winter = new Date("2026-01-15T12:00:00.000Z");
    expect(budapestDayStart(0, winter)).toBe("2026-01-15T00:00:00+01:00");
  });

  it("has no boundary for the whole log", () => {
    expect(periodSince("mind")).toBeNull();
    expect(periodSince("nincs-ilyen")).toBeNull();
  });

  it("counts back the number of days it offers", () => {
    const at = new Date("2026-08-10T12:00:00.000Z");
    expect(periodSince("7", at)).toBe("2026-08-04T00:00:00+02:00");
    expect(periodSince("30", at)).toBe("2026-07-12T00:00:00+02:00");
    expect(AUDIT_PERIODS.map((p) => p.value)).toEqual(["ma", "7", "30", "mind"]);
  });
});
