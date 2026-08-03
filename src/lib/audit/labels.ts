// The audit log in Hungarian.
//
// The database stores what happened in the shape that survives: an action key,
// the column names that moved, and their raw values. This module is the only
// place that turns that into sentences a person reads, for the same reason
// doc-kind.ts exists — the alternative is the vocabulary being reinvented on
// every screen that shows a log entry.
//
// Everything here is pure. The napló, the ügy screen and the partner screen
// render the same event identically because they all come through these
// functions, and audit-labels.test.ts can check the wording without a database.

import { docKindLabel } from "@/lib/domain/doc-kind";
import { formatAmountHu } from "@/lib/format/amount";
import { UGY_STATUS_LABEL, type UgyStatus } from "@/lib/ugy/status";

export type AuditChange = { from?: unknown; to?: unknown };

export type AuditEvent = {
  id: string;
  action: string;
  entityType: string;
  entityId: string;
  entityLabel: string | null;
  actorEmail: string | null;
  actorKind: string;
  changes: Record<string, AuditChange>;
  context: Record<string, unknown>;
  createdAt: string;
};

// The vocabulary the triggers write. Past tense throughout: a log entry is a
// report of something that already happened, never an instruction.
export const AUDIT_ACTION_LABEL: Record<string, string> = {
  "document.erkeztetve": "Irat érkeztetve",
  "document.fajl_csatolva": "Fájl csatolva",
  "document.feldolgozva": "AI feldolgozás kész",
  "document.feldolgozas_sikertelen": "AI feldolgozás sikertelen",
  "document.duplikatum": "Duplikátumnak jelölve",
  "document.iktatva": "Iktatva",
  "document.modositva": "Irat módosítva",
  "document.ervenytelenitve": "Érvénytelenítve",
  "document.elvetve": "Elvetve",
  "document.visszaallitva": "Visszaállítva",
  "document.fizetve": "Kifizetve jelölve",
  "document.fizetes_visszavonva": "Fizetés visszavonva",
  "ugy.megnyitva": "Ügy megnyitva",
  "ugy.statusz": "Ügy állapota változott",
  "ugy.modositva": "Ügy módosítva",
  "partner.letrehozva": "Partner létrehozva",
  "partner.modositva": "Partner módosítva",
  "partner.osszevonva": "Partner összevonva",
  "partner.szetvalasztva": "Összevonás visszavonva",
  "partner.torolve": "Partner törölve",
  "partner.visszaallitva": "Partner visszaállítva",
  "tag.hozzaadva": "Felhasználó hozzáadva",
  "tag.szerepkor": "Szerepkör módosítva",
  "tag.modositva": "Tagság módosítva",
  "irattar.tetel_letrehozva": "Irattári tétel felvéve",
  "irattar.tetel_modositva": "Irattári tétel módosítva",
  "irattar.orzesi_ido": "Őrzési idő módosítva",
  "irattar.tetel_inaktivalva": "Irattári tétel inaktiválva",
  "irattar.tetel_visszaallitva": "Irattári tétel visszaállítva",
  "ceg.letrehozva": "Cég létrehozva",
  "ceg.modositva": "Cégadatok módosítva",
  "export.letoltve": "Könyvelői export letöltve",
};

// An unknown action is shown raw rather than swallowed: if the database holds
// an event this build does not know about, the reader should see that it
// exists instead of an empty row.
export function auditActionLabel(action: string): string {
  return AUDIT_ACTION_LABEL[action] ?? action;
}

// Badge colour. Only three things stand out on purpose — the acts that cannot
// be undone (iktatás, érvénytelenítés) and the one where data leaves the
// company (export). Colouring everything colours nothing.
export function auditActionTone(action: string): string {
  if (action === "document.iktatva" || action === "ugy.megnyitva") return "badge-blue";
  if (
    action === "document.ervenytelenitve" ||
    action === "partner.osszevonva" ||
    action === "document.feldolgozas_sikertelen"
  ) {
    return "badge-red";
  }
  if (action === "export.letoltve") return "badge-violet";
  // Not decoration: an őrzési idő is the date after which records may be
  // destroyed, so a change to one is worth finding in a scrolled feed.
  if (action === "irattar.orzesi_ido") return "badge-orange";
  if (action === "document.fizetve") return "badge-green";
  if (action.endsWith(".elvetve") || action.endsWith(".torolve")) return "badge-amber";
  return "badge-slate";
}

export const AUDIT_ENTITY_LABEL: Record<string, string> = {
  document: "Irat",
  ugy: "Ügy",
  partner: "Partner",
  company_member: "Felhasználó",
  company: "Cég",
  irattari_tetel: "Irattári tétel",
};

export function auditEntityLabel(entityType: string): string {
  return AUDIT_ENTITY_LABEL[entityType] ?? entityType;
}

// The filter offered on the napló. Grouped by what the reader is looking for,
// not by which table the trigger sits on.
export const AUDIT_FILTERS: { value: string; label: string; prefix: string }[] = [
  { value: "irat", label: "Iratok", prefix: "document." },
  { value: "ugy", label: "Ügyek", prefix: "ugy." },
  { value: "partner", label: "Partnerek", prefix: "partner." },
  { value: "irattar", label: "Irattári terv", prefix: "irattar." },
  { value: "hozzaferes", label: "Hozzáférés", prefix: "tag." },
  { value: "export", label: "Export", prefix: "export." },
];

export function auditFilterPrefix(value: string): string | null {
  return AUDIT_FILTERS.find((f) => f.value === value)?.prefix ?? null;
}

// Column names, in one flat map. The same column means the same thing on every
// table it appears on — `targy` is the subject line whether it belongs to an
// irat or an ügy — so splitting this per table would only create two places to
// forget.
const FIELD_LABEL: Record<string, string> = {
  targy: "Tárgy",
  iktatoszam: "Iktatószám",
  alszam: "Alszám",
  ugy_id: "Ügy",
  direction: "Irány",
  doc_kind: "Irattípus",
  processing_status: "Állapot",
  partner_id: "Partner",
  irat_szama: "Bizonylatszám",
  erkezett_at: "Beérkezés",
  issue_date: "Kelt",
  due_date: "Fizetési határidő",
  melleklet_db: "Mellékletek száma",
  kezelesi_feljegyzes: "Kezelési feljegyzés",
  currency: "Pénznem",
  net_amount: "Nettó",
  vat_amount: "ÁFA",
  gross_amount: "Bruttó",
  source: "Forrás",
  duplicate_of_document_id: "Duplikátuma",
  deleted_at: "Elvetve",
  fizetve_at: "Kifizetve",
  fizetesi_megjegyzes: "Fizetési megjegyzés",
  ervenytelenites_indoka: "Érvénytelenítés indoka",
  ervenytelenitette: "Érvénytelenítette",
  ervenytelenitve_at: "Érvénytelenítve",
  created_by: "Létrehozó",
  status: "Állapot",
  hatarido: "Határidő",
  irattari_jel: "Irattári jel",
  irattari_tetel_id: "Irattári tétel",
  tetelszam: "Tételszám",
  orzesi_ido_ev: "Őrzési idő (év)",
  jogszabaly: "Jogszabály",
  megjegyzes: "Megjegyzés",
  sorrend: "Sorrend",
  eloado_user_id: "Előadó",
  parent_ugy_id: "Fölérendelt ügy",
  closed_at: "Lezárva",
  irattarba_helyezve_at: "Irattárba helyezve",
  name: "Név",
  tax_number: "Adószám",
  eu_tax_number: "EU adószám",
  country: "Ország",
  is_supplier: "Szállító",
  is_customer: "Vevő",
  default_payment_term_days: "Fizetési határidő (nap)",
  note: "Megjegyzés",
  bank_account: "Bankszámla",
  address: "Cím",
  email: "E-mail",
  merged_into_partner_id: "Összevonva ebbe",
  role: "Szerepkör",
  accepted_at: "Elfogadva",
  default_currency: "Alapértelmezett pénznem",
};

// The same column does not always mean the same thing. `deleted_at` is the
// one that bites: on an irat it is "elvetve", on a partner "törölve", on an
// irattári tétel "inaktiválva" — and an entry headed "Irattári tétel
// inaktiválva" whose only line reads "Elvetve" contradicts its own title.
const FIELD_LABEL_BY_ENTITY: Record<string, Record<string, string>> = {
  irattari_tetel: { deleted_at: "Inaktiválva" },
  partner: { deleted_at: "Törölve" },
};

export function auditFieldLabel(field: string, entityType?: string): string {
  return (
    FIELD_LABEL_BY_ENTITY[entityType ?? ""]?.[field] ?? FIELD_LABEL[field] ?? field
  );
}

// Foreign keys. Printing a uuid at somebody is not information, so these
// render as what happened to the link rather than as a pair of values.
const OPAQUE_FIELDS = new Set([
  "ugy_id",
  "partner_id",
  "eloado_user_id",
  "parent_ugy_id",
  "duplicate_of_document_id",
  "merged_into_partner_id",
  "irattari_tetel_id",
  "ervenytelenitette",
  "created_by",
  "user_id",
  "company_id",
  "iktatokonyv_id",
]);

// Columns nobody wants to read as a change: they are set by the same act that
// the entry is already named after.
const HIDDEN_FIELDS = new Set(["id", "created_at", "updated_at"]);

const AMOUNT_FIELDS = new Set(["net_amount", "vat_amount", "gross_amount"]);

const TIMESTAMP_FIELDS = new Set([
  "deleted_at",
  "closed_at",
  "irattarba_helyezve_at",
  "ervenytelenitve_at",
  "accepted_at",
]);

const DIRECTION_LABEL: Record<string, string> = {
  bejovo: "Bejövő",
  kimeno: "Kimenő",
  belso: "Belső",
};

const PROCESSING_STATUS_LABEL: Record<string, string> = {
  received: "Feldolgozásra vár",
  extracting: "AI feldolgozás",
  needs_review: "Ellenőrzésre vár",
  iktatva: "Iktatva",
  extraction_failed: "Kinyerés sikertelen",
  ervenytelenitve: "Érvénytelenítve",
  duplicate: "Duplikátum",
};

const ROLE_LABEL: Record<string, string> = {
  owner: "Tulajdonos",
  admin: "Adminisztrátor",
  eloado: "Előadó",
  viewer: "Megtekintő",
};

const SOURCE_LABEL: Record<string, string> = {
  upload: "Feltöltés",
  email: "E-mail",
};

// Long free text is cut here rather than in CSS: a 4000-character megjegyzés
// would otherwise be shipped to the browser in full for a line nobody expands.
const MAX_VALUE_LENGTH = 180;

export function formatAuditValue(field: string, value: unknown): string {
  // The one column where empty is a decision and not a gap. Rendering it as
  // "—" would turn "nem selejtezhető" into "nincs megadva", which is the
  // opposite instruction.
  if (field === "orzesi_ido_ev" && (value === null || value === undefined)) {
    return "nem selejtezhető";
  }
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "Igen" : "Nem";

  if (AMOUNT_FIELDS.has(field)) {
    const n = Number(value);
    return Number.isFinite(n) ? formatAmountHu(n) : String(value);
  }

  const text = String(value);

  if (TIMESTAMP_FIELDS.has(field)) return text.slice(0, 16).replace("T", " ");
  if (field === "doc_kind") return docKindLabel(text);
  if (field === "direction") return DIRECTION_LABEL[text] ?? text;
  if (field === "processing_status") return PROCESSING_STATUS_LABEL[text] ?? text;
  if (field === "status") return UGY_STATUS_LABEL[text as UgyStatus] ?? text;
  if (field === "role") return ROLE_LABEL[text] ?? text;
  if (field === "source") return SOURCE_LABEL[text] ?? text;

  return text.length > MAX_VALUE_LENGTH ? `${text.slice(0, MAX_VALUE_LENGTH)}…` : text;
}

export type AuditChangeLine = {
  field: string;
  label: string;
  // Either from/to (a value moved) or note (a link moved, and the uuids behind
  // it would tell the reader nothing).
  from: string | null;
  to: string | null;
  note: string | null;
};

export function auditChangeLines(event: AuditEvent): AuditChangeLine[] {
  const lines: AuditChangeLine[] = [];

  for (const [field, change] of Object.entries(event.changes ?? {})) {
    if (HIDDEN_FIELDS.has(field)) continue;

    const label = auditFieldLabel(field, event.entityType);

    if (OPAQUE_FIELDS.has(field)) {
      const had = change.from !== null && change.from !== undefined;
      const has = change.to !== null && change.to !== undefined;
      if (!had && !has) continue;
      lines.push({
        field,
        label,
        from: null,
        to: null,
        note: !had ? "hozzárendelve" : !has ? "eltávolítva" : "megváltozott",
      });
      continue;
    }

    lines.push({
      field,
      label,
      from: formatAuditValue(field, change.from),
      to: formatAuditValue(field, change.to),
      note: null,
    });
  }

  // Stable order regardless of how Postgres happened to serialise the jsonb,
  // so the same edit always reads the same way.
  return lines.sort((a, b) => a.label.localeCompare(b.label, "hu"));
}

// Facts that are not column changes. Returns a finished Hungarian sentence, or
// null when the context holds nothing worth a line of its own.
export function auditContextNote(event: AuditEvent): string | null {
  const c = event.context ?? {};

  if (event.action === "export.letoltve") {
    const format = String(c.format ?? "").toUpperCase();
    const from = c.from ? String(c.from) : "";
    const to = c.to ? String(c.to) : "";
    const count = typeof c.count === "number" ? c.count : null;
    const direction =
      typeof c.direction === "string" && c.direction
        ? ` · ${DIRECTION_LABEL[c.direction] ?? c.direction}`
        : "";
    const period = from && to ? `${from} – ${to}` : "";
    const items = count === null ? "" : ` · ${count} irat`;
    return `${format}${period ? ` · ${period}` : ""}${items}${direction}`;
  }

  if (event.action === "document.fajl_csatolva") {
    const name = typeof c.original_filename === "string" ? c.original_filename : "névtelen fájl";
    const sha = typeof c.sha256 === "string" ? c.sha256.slice(0, 12) : null;
    return sha ? `${name} · sha256 ${sha}…` : name;
  }

  if (event.action === "document.erkeztetve") {
    const source = typeof c.source === "string" ? SOURCE_LABEL[c.source] ?? c.source : null;
    return source ? `Forrás: ${source}` : null;
  }

  if (event.action === "partner.osszevonva" || event.action === "partner.szetvalasztva") {
    const survivor = typeof c.survivor_name === "string" ? c.survivor_name : null;
    if (!survivor) return null;
    return event.action === "partner.osszevonva"
      ? `Megmaradt partner: ${survivor}`
      : `Korábban ide volt összevonva: ${survivor}`;
  }

  if (event.action === "irattar.tetel_letrehozva") {
    return `Őrzési idő: ${
      c.orzesi_ido_ev === null || c.orzesi_ido_ev === undefined
        ? "nem selejtezhető"
        : `${c.orzesi_ido_ev} év`
    }`;
  }

  if (event.action === "tag.hozzaadva" && typeof c.role === "string") {
    return `Szerepkör: ${ROLE_LABEL[c.role] ?? c.role}`;
  }

  return null;
}

// Who. The e-mail is the snapshot taken when the event happened, so it stays
// right after the colleague leaves the company.
export function auditActorName(event: AuditEvent): string {
  if (event.actorKind === "system" || !event.actorEmail) return "Rendszer";
  return event.actorEmail;
}

// Where the entry points. Iratok have no screen of their own — the iktatókönyv
// is the list and the review screen is only for unfiled ones — so a document
// entry is not a link. Guessing a destination that 404s is worse than none.
export function auditEntityHref(event: AuditEvent): string | null {
  if (event.entityType === "ugy") return `/ugyek/${event.entityId}`;
  if (event.entityType === "partner") return `/partnerek/${event.entityId}`;
  return null;
}

// "2026. augusztus 1. 14:23" — the log is read as a chronology, so the time
// matters as much as the date.
export function formatAuditTime(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return new Intl.DateTimeFormat("hu-HU", {
    timeZone: "Europe/Budapest",
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}
