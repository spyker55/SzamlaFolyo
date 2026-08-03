"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { futtatNavEgyeztetes } from "@/lib/nav/actions";
import type { NavDirection } from "@/lib/nav/query";

export function NavLekerdezes({
  direction,
  from,
  to,
  disabled,
}: {
  direction: NavDirection;
  from: string;
  to: string;
  disabled: boolean;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  return (
    <div className="space-y-2">
      <button
        type="button"
        className="btn btn-primary"
        disabled={busy || disabled}
        onClick={() => {
          setError(null);
          setMessage(null);
          setBusy(true);
          startTransition(async () => {
            const result = await futtatNavEgyeztetes({ direction, from, to });
            setBusy(false);
            if (result.ok) {
              setMessage(result.message ?? "Kész.");
              router.refresh();
            } else {
              setError(result.error);
            }
          });
        }}
      >
        {busy ? "Lekérdezés fut…" : "Lekérdezés a NAV-tól"}
      </button>
      {busy && (
        <p className="text-xs text-slate-400">
          A NAV 35 naponként és laponként 100 számlát ad vissza, ezért egy hosszabb időszak
          több kérésből áll össze. Ne zárd be az oldalt.
        </p>
      )}
      {error && <p className="alert alert-error">{error}</p>}
      {message && <p className="alert alert-info">{message}</p>}
    </div>
  );
}
