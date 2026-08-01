import { describe, expect, it } from "vitest";
import { hunSupabaseError } from "@/lib/errors";

describe("error messages", () => {
  it("says the write did not happen when the request never arrived", () => {
    // The one thing the user most needs to know after a failure: whether to
    // redo it. A dropped connection is the only case where we can promise
    // nothing was written.
    const text = hunSupabaseError("TypeError: fetch failed", "A mentés nem sikerült.");
    expect(text).toContain("internetkapcsolat");
    expect(text).toContain("nem történt meg");
    // And it must not paste the exception into the sentence.
    expect(text).not.toContain("TypeError");
  });

  it("recognises the shapes a dropped connection actually takes", () => {
    for (const raw of [
      "TypeError: fetch failed",
      "TypeError: Failed to fetch",
      "NetworkError when attempting to fetch resource.",
      "connect ECONNREFUSED 127.0.0.1:443",
      "read ECONNRESET",
      "getaddrinfo ENOTFOUND db.example.supabase.co",
      "Load failed",
    ]) {
      expect(hunSupabaseError(raw, "x"), raw).toContain("internetkapcsolat");
    }
  });

  it("tells an expired session apart from a real refusal", () => {
    expect(hunSupabaseError("JWT expired", "x")).toContain("Jelentkezz be újra");
    expect(hunSupabaseError('new row violates row-level security policy', "x")).toContain(
      "nincs jogosultságod"
    );
  });

  it("keeps an unrecognised message, but out of the sentence", () => {
    const text = hunSupabaseError(
      'duplicate key value violates unique constraint "partner_pkey"',
      "A mentés nem sikerült."
    );
    expect(text.startsWith("A mentés nem sikerült.")).toBe(true);
    // Still there for a bug report — in brackets, not as prose.
    expect(text).toContain("(duplicate key value");
  });

  it("does not swallow a refusal the feature already translated", () => {
    // These strings never reach hunSupabaseError in practice, but if the
    // per-feature checks are ever reordered, the generic layer must not claim
    // a business refusal is a network problem.
    for (const raw of [
      "partners have different tax numbers (23358005-2-43 and 11187433-2-44)",
      "ugy is irattarazott, take it out of the archive before editing it",
      "iktatokonyv is closed for this year",
    ]) {
      expect(hunSupabaseError(raw, "A mentés nem sikerült.")).toContain(raw);
    }
  });
});
