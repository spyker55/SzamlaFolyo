"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createSupabaseBrowserClient } from "@/lib/supabase/client";
import { DOC_KINDS, docKindLabel } from "@/lib/domain/doc-kind";
import { elvet, visszaallit } from "@/lib/inbox/actions";
import { REVIEW_THRESHOLD } from "@/lib/extraction/confidence";
import { EmptyState } from "@/components/ui/page";
import { IconInbox, IconMail, IconUpload } from "@/components/ui/icons";

type InboxDocument = {
  id: string;
  processing_status: string;
  targy: string | null;
  created_at: string;
  duplicate_of_document_id: string | null;
  source: string | null;
  inbound_email_id: string | null;
  doc_kind: string | null;
  document_file: { original_filename: string | null }[];
  duplicateOfIktatoszam?: string | null;
  senderAddress?: string | null;
  senderKnown?: boolean;
  // undefined = no finished extraction yet, so nothing to be uncertain about.
  docKindConfidence?: number;
};

const STATUS_LABEL: Record<string, { text: string; className: string }> = {
  received: { text: "Feldolgozásra vár", className: "badge-slate" },
  extracting: { text: "AI feldolgozás…", className: "badge-blue" },
  needs_review: { text: "Ellenőrzésre vár", className: "badge-amber" },
  extraction_failed: { text: "Kinyerés sikertelen — kézi kitöltés", className: "badge-red" },
  duplicate: { text: "Duplikátum", className: "badge-violet" },
  elvetve: { text: "Elvetve", className: "badge-slate" },
};

const ACTIVE_STATUSES = ["received", "extracting", "needs_review", "extraction_failed", "duplicate"];

// Sentinel for the "no type at all" filter option; "" already means "no filter".
const NO_KIND = "__nincs__";

// Flagged only once the document is actually waiting for a human: before that
// there is no type yet, and after a failed extraction the status already says
// the whole thing needs filling in by hand.
function isTypeUncertain(doc: InboxDocument): boolean {
  if (doc.processing_status !== "needs_review") return false;
  if (!doc.doc_kind) return true;
  return doc.docKindConfidence !== undefined && doc.docKindConfidence < REVIEW_THRESHOLD;
}

export function InboxClient() {
  const [documents, setDocuments] = useState<InboxDocument[]>([]);
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [messages, setMessages] = useState<string[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [kindFilter, setKindFilter] = useState("");
  const [showDiscarded, setShowDiscarded] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const supabaseRef = useRef(createSupabaseBrowserClient());

  const load = useCallback(async () => {
    const base = supabaseRef.current
      .from("document")
      .select(
        "id, processing_status, targy, created_at, duplicate_of_document_id, source, " +
          "inbound_email_id, doc_kind, document_file (original_filename)"
      );

    const scoped = showDiscarded
      ? base.eq("processing_status", "elvetve").not("deleted_at", "is", null)
      : base.in("processing_status", ACTIVE_STATUSES).is("deleted_at", null);

    const { data, error } = await scoped
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

    // How sure the model was about the type. Same rule as the review screen:
    // only finished, successful extractions count, and the newest one wins.
    if (docs.length > 0) {
      const { data: extractions } = await supabaseRef.current
        .from("extraction")
        .select("document_id, field_confidence")
        .in(
          "document_id",
          docs.map((d) => d.id)
        )
        .is("error", null)
        .not("parsed_fields", "is", null)
        .order("finished_at", { ascending: true });

      const confidenceByDoc = new Map<string, number | undefined>();
      for (const e of extractions ?? []) {
        const combined = (e.field_confidence as { combined?: Record<string, number> } | null)
          ?.combined;
        // Ascending order means a later row overwrites an earlier one.
        confidenceByDoc.set(e.document_id as string, combined?.doc_kind);
      }
      for (const d of docs) {
        d.docKindConfidence = confidenceByDoc.get(d.id);
      }
    }

    setDocuments(docs);
  }, [showDiscarded]);

  const discard = useCallback(
    async (id: string) => {
      if (
        !window.confirm(
          "Biztosan elveted ezt az iratot? A Beérkezőből eltűnik, de az „Elvetettek” nézetből visszaállítható."
        )
      ) {
        return;
      }
      setBusyId(id);
      const result = await elvet(id);
      setBusyId(null);
      setMessages(result.ok ? [] : [result.error]);
      load();
    },
    [load]
  );

  const restore = useCallback(
    async (id: string) => {
      setBusyId(id);
      const result = await visszaallit(id);
      setBusyId(null);
      setMessages(result.ok ? [] : [result.error]);
      load();
    },
    [load]
  );

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

  // Only the types actually present are offered: an inbox of four items should
  // not hand the user a sixteen-entry dropdown. The count rides along in the
  // label because a filter whose size you cannot see is a guess.
  const kindOptions = useMemo(() => {
    const counts = new Map<string, number>();
    for (const d of documents) {
      const key = d.doc_kind ?? NO_KIND;
      counts.set(key, (counts.get(key) ?? 0) + 1);
    }

    const options = DOC_KINDS.filter((k) => counts.has(k)).map((k) => ({
      value: k as string,
      label: `${docKindLabel(k)} (${counts.get(k)})`,
    }));
    if (counts.has(NO_KIND)) {
      options.push({ value: NO_KIND, label: `Nincs típus (${counts.get(NO_KIND)})` });
    }
    // Keep the active filter selectable even after its last document leaves,
    // otherwise the select would silently show a blank.
    if (kindFilter && !options.some((o) => o.value === kindFilter)) {
      options.push({ value: kindFilter, label: `${docKindLabel(kindFilter)} (0)` });
    }
    return options;
  }, [documents, kindFilter]);

  const visible = useMemo(() => {
    if (!kindFilter) return documents;
    if (kindFilter === NO_KIND) return documents.filter((d) => !d.doc_kind);
    return documents.filter((d) => d.doc_kind === kindFilter);
  }, [documents, kindFilter]);

  return (
    <div className="mt-4 space-y-4">
      {/* Nothing to drop into the discarded list. */}
      {!showDiscarded && (
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
        className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-10 text-center transition-colors ${
          dragOver
            ? "border-blue-500 bg-blue-50"
            : "border-slate-300 bg-white hover:border-blue-400 hover:bg-slate-50"
        }`}
      >
        <IconUpload className={`h-7 w-7 ${dragOver ? "text-blue-600" : "text-slate-300"}`} />
        <p className="text-sm font-medium text-slate-700">
          {uploading ? "Feltöltés…" : "Húzd ide az iratokat, vagy kattints a tallózáshoz"}
        </p>
        <p className="note">PDF, JPEG, PNG vagy WebP, max. 20 MB</p>
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
      )}

      {messages.length > 0 && (
        <div className="alert alert-warn space-y-1">
          {messages.map((m, i) => (
            <p key={i}>{m}</p>
          ))}
        </div>
      )}

      {loadError && (
        <div className="alert alert-error" role="alert">
          {loadError}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        {kindOptions.length > 0 && (
          <>
            <select
              value={kindFilter}
              onChange={(e) => setKindFilter(e.target.value)}
              className="control w-auto"
              aria-label="Szűrés irat fajtájára"
            >
              <option value="">Minden fajta ({documents.length})</option>
              {kindOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
            {kindFilter && (
              <button
                type="button"
                onClick={() => setKindFilter("")}
                className="btn btn-ghost btn-sm"
              >
                Szűrő törlése
              </button>
            )}
          </>
        )}
        <button
          type="button"
          onClick={() => {
            setShowDiscarded((v) => !v);
            setKindFilter("");
            setMessages([]);
          }}
          className="btn btn-ghost btn-sm ml-auto"
        >
          {showDiscarded ? "← Vissza a beérkezőhöz" : "Elvetett iratok"}
        </button>
      </div>

      <div className="card table-scroll">
        <table className="tbl">
          <thead className="thead">
            <tr>
              <th className="th">Fájl</th>
              <th className="th">Típus</th>
              <th className="th">Tárgy</th>
              <th className="th">Állapot</th>
              <th className="th">Feltöltve</th>
              <th className="th" />
            </tr>
          </thead>
          <tbody>
            {visible.length === 0 && (
              <tr>
                <td colSpan={6}>
                  <EmptyState icon={<IconInbox className="h-8 w-8" />}>
                    {kindFilter
                      ? "Nincs ilyen fajtájú irat."
                      : showDiscarded
                        ? "Nincs elvetett irat."
                        : "Nincs feldolgozás alatt álló irat."}
                  </EmptyState>
                </td>
              </tr>
            )}
            {visible.map((doc) => {
              const status = STATUS_LABEL[doc.processing_status] ?? {
                text: doc.processing_status,
                className: "badge-slate",
              };
              const reviewable =
                doc.processing_status === "needs_review" ||
                doc.processing_status === "extraction_failed";
              return (
                <tr key={doc.id} className="trow">
                  <td className="td">
                    <div className="font-medium text-slate-800">
                      {doc.document_file?.[0]?.original_filename ?? "—"}
                    </div>
                    {doc.inbound_email_id && (
                      <div className="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                        <IconMail className="h-3.5 w-3.5" />
                        {doc.senderAddress ?? "e-mail"}
                        {!doc.senderKnown && (
                          <span
                            className="badge badge-orange"
                            title="Ettől a feladótól még nem iktattál iratot. Ellenőrizd, mielőtt elfogadod."
                          >
                            ismeretlen feladó
                          </span>
                        )}
                      </div>
                    )}
                  </td>
                  <td className="td whitespace-nowrap">
                    {docKindLabel(doc.doc_kind)}
                    {isTypeUncertain(doc) && (
                      <span
                        className="badge badge-amber ml-1.5"
                        title="Az AI nem biztos az irat fajtájában — nézd meg az ellenőrzésnél."
                      >
                        bizonytalan
                      </span>
                    )}
                  </td>
                  <td className="td">{doc.targy ?? "—"}</td>
                  <td className="td">
                    <span className={`badge ${status.className}`}>
                      {status.text}
                      {doc.processing_status === "duplicate" && doc.duplicateOfIktatoszam
                        ? ` → ${doc.duplicateOfIktatoszam}`
                        : ""}
                    </span>
                  </td>
                  <td className="td whitespace-nowrap text-slate-500">
                    {new Date(doc.created_at).toLocaleString("hu-HU")}
                  </td>
                  <td className="td whitespace-nowrap text-right">
                    {showDiscarded ? (
                      <button
                        type="button"
                        onClick={() => restore(doc.id)}
                        disabled={busyId === doc.id}
                        className="btn btn-secondary btn-sm"
                      >
                        Visszaállítás
                      </button>
                    ) : (
                      <>
                        {reviewable && (
                          <Link
                            href={`/ellenorzes/${doc.id}`}
                            className="btn btn-primary btn-sm"
                          >
                            Ellenőrzés
                          </Link>
                        )}
                        <button
                          type="button"
                          onClick={() => discard(doc.id)}
                          disabled={busyId === doc.id}
                          title="Elvetés — nem kap iktatószámot, később visszaállítható"
                          className="btn btn-ghost btn-sm ml-1 hover:text-red-600"
                        >
                          Elvet
                        </button>
                      </>
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
