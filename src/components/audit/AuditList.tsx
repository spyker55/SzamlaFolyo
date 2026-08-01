import Link from "next/link";
import {
  auditActionLabel,
  auditActionTone,
  auditActorName,
  auditChangeLines,
  auditContextNote,
  auditEntityHref,
  formatAuditTime,
  type AuditEvent,
} from "@/lib/audit/labels";
import { EmptyState } from "@/components/ui/page";
import { IconHistory } from "@/components/ui/icons";

// One entry, rendered the same way everywhere it appears.
//
// The shape is deliberately a sentence and not a table row: a log is read
// down the page, and the columns of a table would force every event to carry
// the same fields when a filing has an iktatószám, an export has a period and
// a metadata edit has a list of values that moved.
export function AuditEntry({
  event,
  showEntity = true,
}: {
  event: AuditEvent;
  // Off on an ügy's own screen, where repeating "IKT/7/2026" on every line
  // says nothing the heading has not already said.
  showEntity?: boolean;
}) {
  const lines = auditChangeLines(event);
  const note = auditContextNote(event);
  const href = auditEntityHref(event);

  return (
    <li className="flex gap-3 py-3">
      {/* The rail: a dot per event, joined by the border of the list item, so
          the eye can follow the sequence without numbering it. */}
      <div className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-300" aria-hidden="true" />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span className={`badge ${auditActionTone(event.action)}`}>
            {auditActionLabel(event.action)}
          </span>

          {showEntity && event.entityLabel && (
            <span className="text-sm font-medium text-slate-900">
              {href ? (
                <Link href={href} className="link">
                  {event.entityLabel}
                </Link>
              ) : (
                event.entityLabel
              )}
            </span>
          )}
          {/* No fallback to the entity type when there is no label: an export
              is not "about" the company in any sense worth printing, and
              "Könyvelői export letöltve · Cég" reads like a stray word. */}
        </div>

        {/* Who and when on one line rather than the time in a right-hand
            column: at phone width the column has nowhere to go and lands
            right-aligned between the badge and the name, which reads as a
            third, unrelated field. */}
        <div className="mt-0.5 text-xs text-slate-500">
          {auditActorName(event)}
          <span className="text-slate-300"> · </span>
          {/* nowrap so a narrow screen breaks before the timestamp rather than
              inside it — "2026. augusztus 1." on one line and "11:15" on the
              next is two half-facts. */}
          <span className="whitespace-nowrap tabular-nums text-slate-400">
            {formatAuditTime(event.createdAt)}
          </span>
        </div>

        {note && <div className="mt-1 text-xs text-slate-600">{note}</div>}

        {lines.length > 0 && (
          <dl className="mt-1.5 space-y-0.5 text-xs">
            {lines.map((line) => (
              <div key={line.field} className="flex flex-wrap gap-x-1.5">
                <dt className="text-slate-400">{line.label}:</dt>
                {line.note ? (
                  <dd className="text-slate-600">{line.note}</dd>
                ) : line.from === "—" ? (
                  // Nothing was there before, so there is nothing to strike
                  // through. "Alszám: — → 2" is the same sentence as
                  // "Alszám: 2" with a crossed-out dash in front of it.
                  <dd className="min-w-0 font-medium text-slate-800">{line.to}</dd>
                ) : (
                  <dd className="min-w-0 text-slate-600">
                    <span className="text-slate-400 line-through">{line.from}</span>{" "}
                    <span aria-hidden="true" className="text-slate-300">
                      →
                    </span>{" "}
                    <span className="font-medium text-slate-800">{line.to}</span>
                  </dd>
                )}
              </div>
            ))}
          </dl>
        )}
      </div>
    </li>
  );
}

// The card the ügy and the partner screens end with. The entity is not
// repeated on every line — the page is already about it — but the iratok
// filed under an ügy keep their own iktatószám, which is why they are shown.
export function AuditCard({
  events,
  title = "Előzmények",
  empty,
}: {
  events: AuditEvent[];
  title?: string;
  empty?: React.ReactNode;
}) {
  return (
    <div className="card">
      <div className="card-head">
        <h2 className="card-title">{title}</h2>
      </div>
      <div className="px-4 pb-2">
        <AuditList events={events} empty={empty} />
      </div>
    </div>
  );
}

export function AuditList({
  events,
  showEntity = true,
  empty,
}: {
  events: AuditEvent[];
  showEntity?: boolean;
  empty?: React.ReactNode;
}) {
  if (events.length === 0) {
    return (
      <EmptyState icon={<IconHistory className="h-8 w-8" />}>
        {empty ?? "Ehhez még nem tartozik naplóbejegyzés."}
      </EmptyState>
    );
  }

  return (
    <ul className="divide-y divide-slate-100">
      {events.map((event) => (
        <AuditEntry key={event.id} event={event} showEntity={showEntity} />
      ))}
    </ul>
  );
}
