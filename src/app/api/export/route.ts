import { NextResponse } from "next/server";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { toCsv } from "@/lib/export/csv";
import { fetchExportItems, MAX_EXPORT_ROWS } from "@/lib/export/query";
import { buildZip, zipEntryName, type ZipEntry } from "@/lib/export/zip";
import { exportBaseName, isDateBasis, isIsoDate } from "@/lib/export/period";

// The whole archive is assembled in memory, so it needs a ceiling. At ~200 kB
// a scanned invoice this is thousands of documents — far past a month — and
// the page tells the user to narrow the period rather than the function
// dying with an out-of-memory the user cannot interpret.
const MAX_ZIP_BYTES = 100 * 1024 * 1024;

export async function GET(request: Request) {
  const url = new URL(request.url);
  const from = url.searchParams.get("from") ?? "";
  const to = url.searchParams.get("to") ?? "";
  const basisParam = url.searchParams.get("basis") ?? "erkezett";
  const directionParam = url.searchParams.get("direction");
  const format = url.searchParams.get("format") ?? "csv";

  if (!isIsoDate(from) || !isIsoDate(to) || from > to) {
    return NextResponse.json({ error: "Érvénytelen időszak." }, { status: 400 });
  }
  if (!isDateBasis(basisParam)) {
    return NextResponse.json({ error: "Érvénytelen dátumalap." }, { status: 400 });
  }
  if (format !== "csv" && format !== "zip") {
    return NextResponse.json({ error: "Érvénytelen formátum." }, { status: 400 });
  }
  const direction =
    directionParam === "bejovo" || directionParam === "kimeno" ? directionParam : null;

  const supabase = await createSupabaseServerClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) {
    return NextResponse.json({ error: "Nincs bejelentkezve." }, { status: 401 });
  }

  // Not for authorization — RLS already scopes every row below. This is only
  // to name the file after the company.
  const { data: member } = await supabase
    .from("company_member")
    .select("company:company_id (name)")
    .limit(1)
    .maybeSingle();
  const companyName = (member?.company as unknown as { name: string } | null)?.name ?? "";

  let items;
  try {
    items = await fetchExportItems(supabase, { from, to, basis: basisParam, direction });
  } catch (e) {
    const message = e instanceof Error ? e.message : "ismeretlen hiba";
    return NextResponse.json({ error: `A lekérdezés nem sikerült: ${message}` }, { status: 500 });
  }

  const base = exportBaseName(companyName, from, to);

  if (format === "csv") {
    const csv = toCsv(items);
    return new NextResponse(csv, {
      status: 200,
      headers: {
        "Content-Type": "text/csv; charset=utf-8",
        "Content-Disposition": contentDisposition(`${base}.csv`),
        "Cache-Control": "no-store",
      },
    });
  }

  if (items.length === 0) {
    return NextResponse.json(
      { error: "Ebben az időszakban nincs iktatott irat." },
      { status: 404 }
    );
  }
  if (items.length >= MAX_EXPORT_ROWS) {
    return NextResponse.json(
      { error: `Az időszak több mint ${MAX_EXPORT_ROWS} iratot tartalmaz, válassz rövidebbet.` },
      { status: 413 }
    );
  }

  const taken = new Set<string>();
  const entries: ZipEntry[] = [];
  const missing: string[] = [];
  let bytes = 0;

  for (const item of items) {
    if (!item.file) {
      missing.push(item.iktatoszam ?? item.id);
      continue;
    }
    // The user's own client, so an object outside the company's folder is
    // unreadable here exactly as it is everywhere else.
    const { data, error } = await supabase.storage.from("iratok").download(item.file.storagePath);
    if (error || !data) {
      missing.push(item.iktatoszam ?? item.id);
      continue;
    }
    const buffer = new Uint8Array(await data.arrayBuffer());
    bytes += buffer.length;
    if (bytes > MAX_ZIP_BYTES) {
      return NextResponse.json(
        { error: "Az iratok együtt túl nagyok, válassz rövidebb időszakot." },
        { status: 413 }
      );
    }
    entries.push({
      name: zipEntryName(item.iktatoszam, item.file.originalFilename, taken),
      data: buffer,
      modified: item.erkezettAt ? new Date(`${item.erkezettAt}T12:00:00Z`) : undefined,
    });
  }

  // The index travels with the files: whoever opens the archive gets the
  // table too, and can tell from it which iratok have no file attached.
  entries.unshift({
    name: `${base}.csv`,
    data: new TextEncoder().encode(toCsv(items)),
  });

  if (missing.length > 0) {
    entries.push({
      name: "HIANYZO_FAJLOK.txt",
      data: new TextEncoder().encode(
        "Ezekhez az iratokhoz nem sikerült fájlt csatolni:\n" + missing.join("\n") + "\n"
      ),
    });
  }

  const zip = buildZip(entries);

  return new NextResponse(zip as unknown as BodyInit, {
    status: 200,
    headers: {
      "Content-Type": "application/zip",
      "Content-Disposition": contentDisposition(`${base}.zip`),
      "Content-Length": String(zip.length),
      "Cache-Control": "no-store",
    },
  });
}

// The company name may contain accents, so the filename needs both the ASCII
// fallback and the RFC 5987 form.
function contentDisposition(filename: string): string {
  const ascii = filename.replace(/[^\x20-\x7e]/g, "_").replace(/["\\]/g, "_");
  return `attachment; filename="${ascii}"; filename*=UTF-8''${encodeURIComponent(filename)}`;
}
