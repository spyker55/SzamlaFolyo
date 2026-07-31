import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { DOC_KINDS, DOC_KIND_LABEL, DOC_KIND_OPTIONS, docKindLabel } from "@/lib/domain/doc-kind";

const MIGRATIONS_DIR = join(process.cwd(), "supabase", "migrations");

// Rebuild public.doc_kind the way Postgres would, straight from the migration
// files. The drift this catches is the real one: a value added to the
// TypeScript list without a migration (the UI offers a type the database will
// reject on iktatás), or a migration whose value never reaches the UI.
function docKindOrderFromMigrations(): string[] {
  const files = readdirSync(MIGRATIONS_DIR)
    .filter((f) => f.endsWith(".sql"))
    .sort();

  let order: string[] = [];

  for (const file of files) {
    // Strip line comments so prose mentioning a label cannot be read as DDL.
    const sql = readFileSync(join(MIGRATIONS_DIR, file), "utf8").replace(/--[^\n]*/g, "");

    const created = sql.match(/create type public\.doc_kind as enum\s*\(([^)]*)\)/i);
    if (created) {
      order = [...created[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
    }

    const adds = sql.matchAll(
      /alter type public\.doc_kind add value(?:\s+if not exists)?\s+'([a-z_]+)'(?:\s+(before|after)\s+'([a-z_]+)')?\s*;/gi
    );
    for (const [, value, position, anchor] of adds) {
      if (order.includes(value)) continue;
      if (!position) {
        order.push(value);
        continue;
      }
      const at = order.indexOf(anchor);
      if (at === -1) {
        throw new Error(`${file}: '${anchor}' is used as an anchor before it exists`);
      }
      order.splice(position.toLowerCase() === "before" ? at : at + 1, 0, value);
    }
  }

  return order;
}

describe("doc_kind vocabulary", () => {
  it("matches the enum the migrations build", () => {
    expect([...DOC_KINDS]).toEqual(docKindOrderFromMigrations());
  });

  it("keeps the Hungarian invoice families apart", () => {
    // These four are separate on purpose: a díjbekérő is not an accounting
    // document at all, and a sztornó cancels an invoice rather than being one.
    for (const kind of ["szamla", "elolegszamla", "helyesbito_szamla", "sztorno_szamla", "dijbekero"]) {
      expect(DOC_KINDS).toContain(kind);
    }
  });

  it("labels every value, in enum order", () => {
    expect(DOC_KIND_OPTIONS.map((o) => o.value)).toEqual([...DOC_KINDS]);
    for (const kind of DOC_KINDS) {
      expect(DOC_KIND_LABEL[kind], kind).toMatch(/\S/);
    }
  });

  it("shows an unknown value rather than mislabelling it", () => {
    // A database row from a newer migration must not silently read "Egyéb".
    expect(docKindLabel("valami_uj")).toBe("valami_uj");
    expect(docKindLabel(null)).toBe("—");
    expect(docKindLabel("")).toBe("—");
    expect(docKindLabel("sztorno_szamla")).toBe("Sztornó számla");
  });
});
