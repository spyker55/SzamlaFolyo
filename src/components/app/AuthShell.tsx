import type { ReactNode } from "react";

// The three screens outside the app shell — sign in, sign up, create company —
// were three copies of the same card that had already drifted apart. This is
// the one of them.
export function AuthShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 text-sm font-bold text-white">
            Sz
          </span>
          <span className="text-lg font-semibold tracking-tight text-slate-900">
            Számlafolyó
          </span>
        </div>

        <div className="card p-7">
          <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
          {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
          {children}
        </div>

        {footer && <p className="mt-4 text-center text-sm text-slate-500">{footer}</p>}
      </div>
    </main>
  );
}
