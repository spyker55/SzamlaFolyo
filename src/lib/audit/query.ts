import type { SupabaseClient } from "@supabase/supabase-js";
import type { AuditEvent } from "@/lib/audit/labels";

// Reading the log. Every screen that shows audit entries comes through here,
// so the napló and the "Előzmények" card on an ügy cannot end up querying the
// same table with different assumptions about ordering or shape.

type Row = {
  id: string;
  action: string;
  entity_type: string;
  entity_id: string;
  entity_label: string | null;
  actor_user_id: string | null;
  actor_email: string | null;
  actor_kind: string;
  changes: Record<string, { from?: unknown; to?: unknown }> | null;
  context: Record<string, unknown> | null;
  created_at: string;
};

const SELECT =
  "id, action, entity_type, entity_id, entity_label, actor_user_id, actor_email, actor_kind, changes, context, created_at";

export function toAuditEvent(row: Row): AuditEvent {
  return {
    id: row.id,
    action: row.action,
    entityType: row.entity_type,
    entityId: row.entity_id,
    entityLabel: row.entity_label,
    actorEmail: row.actor_email,
    actorKind: row.actor_kind,
    changes: row.changes ?? {},
    context: row.context ?? {},
    createdAt: row.created_at,
  };
}

export type AuditFilter = {
  entityType?: string;
  entityId?: string;
  // An ügy's history is not only its own row: the iratok filed under it are
  // the story. Their events are fetched in the same round trip by id.
  entityIds?: string[];
  // 'document.', 'ugy.', … — matches the action vocabulary the triggers write.
  actionPrefix?: string | null;
  actorUserId?: string | null;
  since?: string | null;
  limit: number;
  offset?: number;
};

export async function fetchAuditEvents(
  supabase: SupabaseClient,
  filter: AuditFilter
): Promise<AuditEvent[]> {
  // range() rather than limit(): the two set conflicting PostgREST headers, and
  // paging needs the offset anyway.
  const offset = filter.offset ?? 0;

  let query = supabase
    .from("audit_event")
    .select(SELECT)
    .order("created_at", { ascending: false })
    .range(offset, offset + filter.limit - 1);

  if (filter.entityType) query = query.eq("entity_type", filter.entityType);
  if (filter.entityId) query = query.eq("entity_id", filter.entityId);
  if (filter.entityIds && filter.entityIds.length > 0) {
    query = query.in("entity_id", filter.entityIds);
  }
  if (filter.actionPrefix) query = query.like("action", `${filter.actionPrefix}%`);
  if (filter.actorUserId) query = query.eq("actor_user_id", filter.actorUserId);
  if (filter.since) query = query.gte("created_at", filter.since);

  const { data } = await query;
  return ((data ?? []) as unknown as Row[]).map(toAuditEvent);
}

// Period boundaries in Budapest time, because "ma" means the day the user is
// having, not the day UTC is having. At 00:30 local in summer those are
// different dates, and a log that hides the last half hour of work is worse
// than one with no filter at all.
export function budapestDayStart(daysBack: number, now: Date = new Date()): string {
  const day = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Budapest" }).format(
    new Date(now.getTime() - daysBack * 86_400_000)
  );
  const offset =
    new Intl.DateTimeFormat("en-US", {
      timeZone: "Europe/Budapest",
      timeZoneName: "longOffset",
    })
      .formatToParts(now)
      .find((p) => p.type === "timeZoneName")?.value.replace("GMT", "") || "+00:00";
  return `${day}T00:00:00${offset}`;
}

export const AUDIT_PERIODS: { value: string; label: string; days: number | null }[] = [
  { value: "ma", label: "Ma", days: 0 },
  { value: "7", label: "Utolsó 7 nap", days: 6 },
  { value: "30", label: "Utolsó 30 nap", days: 29 },
  { value: "mind", label: "Teljes napló", days: null },
];

export function periodSince(value: string, now: Date = new Date()): string | null {
  const period = AUDIT_PERIODS.find((p) => p.value === value);
  if (!period || period.days === null) return null;
  return budapestDayStart(period.days, now);
}
