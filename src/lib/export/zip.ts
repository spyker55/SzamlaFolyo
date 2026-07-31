// A minimal ZIP writer, stored (uncompressed) entries only.
//
// The archive holds PDFs and JPEGs, which are already compressed — deflating
// them again would burn CPU in a serverless function for roughly nothing. So
// no compression, no dependency, and an archive every tool on earth opens.
//
// Deliberate limits, all far above what a monthly handover produces: no
// Zip64, so under 65535 entries and under 4 GB total; the route caps the byte
// count long before either matters.

export type ZipEntry = {
  name: string;
  data: Uint8Array;
  modified?: Date;
};

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[i] = c >>> 0;
  }
  return table;
})();

export function crc32(data: Uint8Array): number {
  let c = 0xffffffff;
  for (let i = 0; i < data.length; i++) c = CRC_TABLE[(c ^ data[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

// MS-DOS timestamp: 2-second resolution, no timezone, epoch 1980. Anything
// before 1980 cannot be represented, so clamp instead of writing garbage.
function dosDateTime(d: Date): { time: number; date: number } {
  const year = Math.max(1980, d.getUTCFullYear());
  return {
    time:
      (d.getUTCHours() << 11) | (d.getUTCMinutes() << 5) | (d.getUTCSeconds() >> 1),
    date:
      ((year - 1980) << 9) | ((d.getUTCMonth() + 1) << 5) | d.getUTCDate(),
  };
}

const LOCAL_HEADER = 30;
const CENTRAL_HEADER = 46;
const EOCD = 22;
// Bit 11 declares the filename is UTF-8, which is what makes "díjbekérő.pdf"
// survive into the bookkeeper's unzip.
const FLAG_UTF8 = 0x0800;

export function buildZip(entries: ZipEntry[]): Uint8Array {
  const encoder = new TextEncoder();
  const prepared = entries.map((e) => ({
    nameBytes: encoder.encode(e.name),
    data: e.data,
    crc: crc32(e.data),
    ...dosDateTime(e.modified ?? new Date()),
  }));

  let localSize = 0;
  let centralSize = 0;
  for (const e of prepared) {
    localSize += LOCAL_HEADER + e.nameBytes.length + e.data.length;
    centralSize += CENTRAL_HEADER + e.nameBytes.length;
  }

  const out = new Uint8Array(localSize + centralSize + EOCD);
  const view = new DataView(out.buffer);
  let offset = 0;

  const offsets: number[] = [];

  for (const e of prepared) {
    offsets.push(offset);
    view.setUint32(offset, 0x04034b50, true); // local file header
    view.setUint16(offset + 4, 20, true); // version needed
    view.setUint16(offset + 6, FLAG_UTF8, true);
    view.setUint16(offset + 8, 0, true); // method: stored
    view.setUint16(offset + 10, e.time, true);
    view.setUint16(offset + 12, e.date, true);
    view.setUint32(offset + 14, e.crc, true);
    view.setUint32(offset + 18, e.data.length, true); // compressed
    view.setUint32(offset + 22, e.data.length, true); // uncompressed
    view.setUint16(offset + 26, e.nameBytes.length, true);
    view.setUint16(offset + 28, 0, true); // extra field length
    out.set(e.nameBytes, offset + LOCAL_HEADER);
    out.set(e.data, offset + LOCAL_HEADER + e.nameBytes.length);
    offset += LOCAL_HEADER + e.nameBytes.length + e.data.length;
  }

  const centralStart = offset;

  prepared.forEach((e, i) => {
    view.setUint32(offset, 0x02014b50, true); // central directory header
    view.setUint16(offset + 4, 20, true); // version made by
    view.setUint16(offset + 6, 20, true); // version needed
    view.setUint16(offset + 8, FLAG_UTF8, true);
    view.setUint16(offset + 10, 0, true); // method: stored
    view.setUint16(offset + 12, e.time, true);
    view.setUint16(offset + 14, e.date, true);
    view.setUint32(offset + 16, e.crc, true);
    view.setUint32(offset + 20, e.data.length, true);
    view.setUint32(offset + 24, e.data.length, true);
    view.setUint16(offset + 28, e.nameBytes.length, true);
    view.setUint16(offset + 30, 0, true); // extra
    view.setUint16(offset + 32, 0, true); // comment
    view.setUint16(offset + 34, 0, true); // disk number
    view.setUint16(offset + 36, 0, true); // internal attributes
    view.setUint32(offset + 38, 0, true); // external attributes
    view.setUint32(offset + 42, offsets[i], true);
    out.set(e.nameBytes, offset + CENTRAL_HEADER);
    offset += CENTRAL_HEADER + e.nameBytes.length;
  });

  view.setUint32(offset, 0x06054b50, true); // end of central directory
  view.setUint16(offset + 4, 0, true); // this disk
  view.setUint16(offset + 6, 0, true); // disk with central directory
  view.setUint16(offset + 8, prepared.length, true);
  view.setUint16(offset + 10, prepared.length, true);
  view.setUint32(offset + 12, centralSize, true);
  view.setUint32(offset + 16, centralStart, true);
  view.setUint16(offset + 20, 0, true); // comment length

  return out;
}

// The point of the archive is that a file can be traced back to a row of the
// CSV, so the iktatoszam leads the name. The slash in IKT/6-2/2026 would
// create directories, so it becomes a dash.
export function zipEntryName(
  iktatoszam: string | null,
  originalFilename: string | null,
  taken: Set<string>
): string {
  const prefix = (iktatoszam ?? "iktatoszam-nelkul").replace(/[/\\]/g, "-");
  const original = (originalFilename ?? "irat").replace(/[/\\]/g, "-");

  const dot = original.lastIndexOf(".");
  const stem = dot > 0 ? original.slice(0, dot) : original;
  const ext = dot > 0 ? original.slice(dot) : "";

  // Control characters and the characters Windows forbids in a filename;
  // accented letters are kept, because the archive declares UTF-8.
  const clean = (s: string) =>
    s.replace(/[\u0000-\u001f<>:"|?*]/g, "_").trim();

  const base = `${clean(prefix)}_${clean(stem)}`.slice(0, 120);
  let name = `${base}${clean(ext)}`;

  // Two rows can point at the same blob (a duplicate reuses the original's
  // storage path), and an archive with two identical names is malformed.
  let n = 2;
  while (taken.has(name)) {
    name = `${base}_${n}${clean(ext)}`;
    n++;
  }
  taken.add(name);
  return name;
}
