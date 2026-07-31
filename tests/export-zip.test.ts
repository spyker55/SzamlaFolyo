import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { buildZip, crc32, zipEntryName } from "@/lib/export/zip";

describe("crc32", () => {
  it("matches the reference value", () => {
    // The canonical check value from the CRC-32 specification.
    expect(crc32(new TextEncoder().encode("123456789"))).toBe(0xcbf43926);
    expect(crc32(new Uint8Array(0))).toBe(0);
  });
});

describe("zipEntryName", () => {
  it("puts the iktatoszam first and does not create directories", () => {
    expect(zipEntryName("IKT/6-2/2026", "websupport számla.pdf", new Set())).toBe(
      "IKT-6-2-2026_websupport számla.pdf"
    );
  });

  it("keeps accents, because the archive declares UTF-8", () => {
    expect(zipEntryName("IKT/1-1/2026", "díjbekérő.pdf", new Set())).toContain("díjbekérő");
  });

  it("does not emit the same name twice", () => {
    // Two iratok can point at one blob: a duplicate reuses the original's
    // storage path, and an archive with two identical names is malformed.
    const taken = new Set<string>();
    const a = zipEntryName("IKT/4-1/2026", "dijbekero.pdf", taken);
    const b = zipEntryName("IKT/4-1/2026", "dijbekero.pdf", taken);
    expect(a).toBe("IKT-4-1-2026_dijbekero.pdf");
    expect(b).toBe("IKT-4-1-2026_dijbekero_2.pdf");
  });

  it("survives a missing iktatoszam and a missing filename", () => {
    const name = zipEntryName(null, null, new Set());
    expect(name).toBe("iktatoszam-nelkul_irat");
  });

  it("strips characters a filesystem would refuse", () => {
    expect(zipEntryName("IKT/1-1/2026", 'a:b"c|d?e*f.pdf', new Set())).toBe(
      "IKT-1-1-2026_a_b_c_d_e_f.pdf"
    );
  });
});

describe("buildZip", () => {
  let dir: string;

  beforeAll(() => {
    dir = mkdtempSync(join(tmpdir(), "szamlafolyo-zip-"));
  });

  afterAll(() => {
    rmSync(dir, { recursive: true, force: true });
  });

  // The point of writing our own writer is that the bookkeeper's unzip must
  // open it, so the test asks a real unzip rather than our own reader.
  it("produces an archive the system unzip accepts and extracts intact", () => {
    const pdfLike = new Uint8Array(4096);
    for (let i = 0; i < pdfLike.length; i++) pdfLike[i] = (i * 37) % 251;
    const csv = new TextEncoder().encode("Iktatószám;Bruttó\r\n\"IKT/1-1/2026\";16975,00\r\n");

    const zip = buildZip([
      { name: "napfeny_2026-07.csv", data: csv },
      { name: "IKT-1-1-2026_díjbekérő.pdf", data: pdfLike, modified: new Date("2026-07-31T12:00:00Z") },
    ]);

    const path = join(dir, "export.zip");
    writeFileSync(path, zip);

    // -t verifies every CRC in the archive.
    const tested = execFileSync("unzip", ["-t", path], { encoding: "utf8" });
    expect(tested).toContain("No errors detected");

    execFileSync("unzip", ["-o", "-q", path, "-d", join(dir, "out")]);

    const extractedCsv = readFileSync(join(dir, "out", "napfeny_2026-07.csv"));
    expect(new TextDecoder().decode(extractedCsv)).toContain("16975,00");

    const extractedPdf = readFileSync(join(dir, "out", "IKT-1-1-2026_díjbekérő.pdf"));
    expect(new Uint8Array(extractedPdf)).toEqual(pdfLike);
  });

  it("writes an empty archive rather than a corrupt one", () => {
    const zip = buildZip([]);
    // A bare end-of-central-directory record: 22 bytes, nothing else.
    expect(zip.length).toBe(22);
    expect([...zip.slice(0, 4)]).toEqual([0x50, 0x4b, 0x05, 0x06]);

    const path = join(dir, "empty.zip");
    writeFileSync(path, zip);
    // zipinfo reports an empty archive on stdout but exits 1, so the throw is
    // the expected path — what matters is that it recognises the file.
    let output: string;
    try {
      output = execFileSync("zipinfo", ["-t", path], { encoding: "utf8" });
    } catch (e) {
      output = String((e as { stdout?: string }).stdout ?? "");
    }
    expect(output).toContain("Empty zipfile");
  });

  it("stores rather than compresses", () => {
    const data = new TextEncoder().encode("x".repeat(10_000));
    const path = join(dir, "stored.zip");
    writeFileSync(path, buildZip([{ name: "a.txt", data }]));
    const listed = execFileSync("zipinfo", ["-l", path], { encoding: "utf8" });
    // "stor" is the method column; deflating an already-compressed PDF would
    // burn CPU in a serverless function for nothing.
    expect(listed).toContain("stor");
  });
});
