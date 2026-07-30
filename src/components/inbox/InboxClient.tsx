"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { createSupabaseBrowserClient } from "@/lib/supabase/client";

type InboxDocument = {
  id: string;
  processing_status: string;
  targy: string | null;
  created_at: string;
  duplicate_of_document_id: string | null;
  source: string | null;
  inbound_email_id: string | null;
  document_file: { original_filename: string | null }[];
  duplicateOfIktatoszam?: string | null;
  senderAddress?: string | null;
  senderKnown?: boolean;
};

const STATUS_LABEL: Record<string, { text: string; className: string }> = {
  received: { text: "Feldolgozásra vár", className: "bg-gray-100 text-gray-700" },
  extracting: { text: "AI feldolgozás…", className: "bg-blue-100 text-blue-700" },
  needs_review: { text: "Ellenőrzésre vár", className: "bg-amber-100 text-amber-800" },
  extraction_failed: { text: "Kinyerés sikertelen — kézi kitöltés", className: "bg-red-100 text-red-700" },
  duplicate: { text: "Duplikátum", className: "bg-purple-100 text-purple-700" },
};

const ACTIVE_STATUSES = ["received", "extracting", "needs_review", "extraction_failed", "duplicate"];

export function InboxClient() {
  const [documents, setDocuments] = useState<InboxDocument[]>([]);
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [messages, setMessages] = useState<string[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const supabaseRef = useRef(createSupabaseBrowserClient());

  const load = useCallback(async () => {
    const { data, error } = await supabaseRef.current
      .from("document")
      .select(
        "id, processing_status, targy, created_at, duplicate_of_document_id, source, " +
          "inbound_email_id, document_file (original_filename)"
      )
      .in("processing_status", ACTIVE_STATUSES)
      .is("deleted_at", null)
      .order("created_at", { ascending: false })
      .limit(100);

    if (error) {
      // A query failure must never masquerade as an empty inbox.
      setLoadError("A lista betöltése nem sikerült: " + error.message);
      return;
    }
    setLoadError(null);

    const docs = (data ?? []) as unknown as InboxDocument[];

    // Resolve the original iktatoszam for duplicates in a second query
    // (PostgREST self-join embeds proved fragile).
    const originalIds = [
      ...new Set(
        docs
          .filter((d) => d.processing_status === "duplicate" && d.duplicate_of_document_id)
          .map((d) => d.duplicate_of_document_id as string)
      ),
    ];
    if (originalIds.length > 0) {
      const { data: originals } = await supabaseRef.current
        .from("document")
        .select("id, iktatoszam")
        .in("id", originalIds);
      const byId = new Map((originals ?? []).map((o) => [o.id as string, o.iktatoszam as string | null]));
      for (const d of docs) {
        if (d.duplicate_of_document_id) {
          d.duplicateOfIktatoszam = byId.get(d.duplicate_of_document_id) ?? null;
        }
      }
    }

    // Same shape as above: resolve the sending address with a plain second
    // query rather than an embed.
    const emailIds = [
      ...new Set(docs.filter((d) => d.inbound_email_id).map((d) => d.inbound_email_id as string)),
    ];
    if (emailIds.length > 0) {
      const { data: emails } = await supabaseRef.current
        .from("inbound_email")
        .select("id, mail_from, sender_known")
        .in("id", emailIds);
      const byId = new Map(
        (emails ?? []).map((e) => [
          e.id as string,
          { from: e.mail_from as string | null, known: Boolean(e.sender_known) },
        ])
      );
      for (const d of docs) {
        const info = d.inbound_email_id ? byId.get(d.inbound_email_id) : undefined;
        d.senderAddress = info?.from ?? null;
        d.senderKnown = info?.known ?? false;
      }
    }

    setDocuments(docs);
  }, []);

  useEffect(() => {
    load();
    const interval = setInterval(load, 4000);
    return () => clearInterval(interval);
  }, [load]);

  const upload = useCallback(
    async (files: FileList | File[]) => {
      setUploading(true);
      setMessages([]);
      const formData = new FormData();
      for (const file of Array.from(files)) formData.append("files", file);

      try {
        const res = await fetch("/api/upload", { method: "POST", body: formData });
        const body = await res.json();
        if (!res.ok) {
          setMessages([body.error ?? "A feltöltés nem sikerült."]);
        } else {
          const msgs: string[] = [];
          for (const r of body.results ?? []) {
            if (r.status === "duplicate") {
              msgs.push(
                `${r.filename}: már iktatott irat duplikátuma` +
                  (r.duplicateOfIktatoszam ? ` (${r.duplicateOfIktatoszam})` : "") +
                  " — nem kap új iktatószámot."
              );
            } else if (r.status === "rejected") {
              msgs.push(`${r.filename}: ${r.reason}`);
            }
          }
          setMessages(msgs);
        }
      } catch {
        setMessages(["A feltöltés nem sikerült — hálózati hiba."]);
      } finally {
        setUploading(false);
        load();
      }
    },
    [load]
  );

  return (
    <div className="mt-4 space-y-4">
      <div
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          if (e.dataTransfer.files.length > 0) upload(e.dataTransfer.files);
        }}
        onClick={() => fileInputRef.current?.click()}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === "Enter") fileInputRef.current?.click();
        }}
        className={`flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-10 text-center transition-colors ${
          dragOver ? "border-blue-500 bg-blue-50" : "border-gray-300 bg-white hover:border-gray-400"
        }`}
      >
        <p className="text-sm font-medium text-gray-700">
          {uploading ? "Feltöltés…" : "Húzd ide az iratokat, vagy kattints a tallózáshoz"}
        </p>
        <p className="mt-1 text-xs text-gray-400">PDF, JPEG, PNG vagy WebP, max. 20 MB</p>
        <input
          ref={fileInputRef}
          type="file"
          multiple
          accept="application/pdf,image/jpeg,image/png,image/webp"
          className="hidden"
          onChange={(e) => {
            if (e.target.files && e.target.files.length > 0) upload(e.target.files);
            e.target.value = "";
          }}
        />
      </div>

      {messages.length > 0 && (
        <div className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
          {messages.map((m, i) => (
            <p key={i}>{m}</p>
          ))}
        </div>
      )}

      {loadError && (
        <div className="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">
          {loadError}
        </div>
      )}

      <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase text-gray-500">
            <tr>
              <th className="px-4 py-2">Fájl</th>
              <th className="px-4 py-2">Tárgy</th>
              <th className="px-4 py-2">Állapot</th>
              <th className="px-4 py-2">Feltöltve</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            {documents.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-gray-400">
                  Nincs feldolgozás alatt álló irat.
                </td>
              </tr>
            )}
            {documents.map((doc) => {
              const status = STATUS_LABEL[doc.processing_status] ?? {
                text: doc.processing_status,
                className: "bg-gray-100 text-gray-700",
              };
              const reviewable =
                doc.processing_status === "needs_review" ||
                doc.processing_status === "extraction_failed";
              return (
                <tr key={doc.id} className="border-b border-gray-100 last:border-0">
                  <td className="px-4 py-2">
                    <div>{doc.document_file?.[0]?.original_filename ?? "—"}</div>
                    {doc.inbound_email_id && (
                      <div className="mt-0.5 text-xs text-gray-500">
                        ✉ {doc.senderAddress ?? "e-mail"}
                        {!doc.senderKnown && (
                          <span
                            className="ml-1 rounded bg-orange-100 px-1 py-0.5 text-orange-800"
                            title="Ettől a feladótól még nem iktattál iratot. Ellenőrizd, mielőtt elfogadod."
                          >
                            ismeretlen feladó
                          </span>
                        )}
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-2">{doc.targy ?? "—"}</td>
                  <td className="px-4 py-2">
                    <span className={`rounded-full px-2 py-0.5 text-xs ${status.className}`}>
                      {status.text}
                      {doc.processing_status === "duplicate" && doc.duplicateOfIktatoszam
                        ? ` → ${doc.duplicateOfIktatoszam}`
                        : ""}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-gray-500">
                    {new Date(doc.created_at).toLocaleString("hu-HU")}
                  </td>
                  <td className="px-4 py-2 text-right">
                    {reviewable && (
                      <Link
                        href={`/ellenorzes/${doc.id}`}
                        className="rounded-md bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700"
                      >
                        Ellenőrzés
                      </Link>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
