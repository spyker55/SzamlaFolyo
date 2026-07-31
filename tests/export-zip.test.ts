import { execFileSync } from "node:child_process";
import { mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { buildZip, crc32, zipEntryName } from "@/lib/export/zip";

function indexOfBytes(haystack: Uint8Array, needle: Uint8Array, from: number): number {
  outer: for (let i = from; i <= haystack.length - needle.length; i++) {
    for (let j = 0; j < needle.length; j++) {
      if (haystack[i + j] !== needle[j]) continue outer;
    }
    return i;
  }
  return -1;
}

function occurrences(haystack: Uint8Array, needle: Uint8Array): number {
  let count = 0;
  let at = indexOfBytes(haystack, needle, 0);
  while (at !== -1) {
    count++;
    at = indexOfBytes(haystack, needle, at + 1);
  }
  return count;
}

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

    // The file is found by extension, not by the name we put in: Info-ZIP
    // rewrites a non-ASCII name according to the machine's locale, and on
    // LANG=C.UTF-8 (what GitHub's runners set) it mangles it into mojibake.
    // That is the extractor's business — what this test owns is that the
    // bytes come back unchanged. The name itself is checked against the
    // archive below, where we control the answer.
    const extracted = readdirSync(join(dir, "out"));
    expect(extracted).toHaveLength(2);
    const pdfName = extracted.find((f) => f.endsWith(".pdf"));
    expect(pdfName).toBeDefined();
    expect(new Uint8Array(readFileSync(join(dir, "out", pdfName!)))).toEqual(pdfLike);
  });

  it("stores an accented filename as UTF-8 and says so in the flags", () => {
    const name = "IKT-6-1-2026_díjbekérő.pdf";
    const zip = buildZip([{ name, data: new Uint8Array([1, 2, 3]) }]);
    const nameBytes = new TextEncoder().encode(name);

    // Once in the local header, once in the central directory.
    expect(occurrences(zip, nameBytes)).toBe(2);
    // Straight after the 30-byte local file header, so it is the name field
    // and not a coincidence somewhere in the payload.
    expect(indexOfBytes(zip, nameBytes, 0)).toBe(30);

    // Bit 11 of the general purpose flag: "the filename is UTF-8". Without it
    // an extractor is entitled to read the bytes as CP437.
    const view = new DataView(zip.buffer, zip.byteOffset, zip.byteLength);
    expect(view.getUint16(6, true) & 0x0800).toBe(0x0800);
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
