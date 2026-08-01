import type { ReactNode } from "react";

// One heading shape for every screen. Each page used to write its own
// `<h1 className="text-xl font-semibold">`, so the title sat at a slightly
// different size and distance from the content on every route.
export function PageHeader({
  lead,
  title,
  description,
  actions,
}: {
  // Rendered in gray before the title, the way the reference design opens a
  // page: quiet context, then the thing itself.
  lead?: string;
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
          {lead && <span className="font-normal text-slate-400">{lead} </span>}
          {title}
        </h1>
        {description && (
          <div className="mt-1 max-w-3xl text-sm text-slate-500">{description}</div>
        )}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function EmptyState({
  icon,
  children,
  hint,
}: {
  icon?: ReactNode;
  children: ReactNode;
  hint?: ReactNode;
}) {
  return (
    <div className="empty">
      {icon && <div className="text-slate-300">{icon}</div>}
      <p className="text-slate-500">{children}</p>
      {hint && <p className="max-w-md text-xs text-slate-400">{hint}</p>}
    </div>
  );
}
