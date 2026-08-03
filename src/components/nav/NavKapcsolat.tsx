"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { mentNavKapcsolat, probaNavKapcsolat, torolNavKapcsolat } from "@/lib/nav/actions";

export type KapcsolatAllapot = {
  beallitva: boolean;
  taxNumber: string | null;
  login: string | null;
  environment: "production" | "test";
  lastOkAt: string | null;
  lastError: string | null;
};

const ENV_LABEL: Record<"production" | "test", string> = {
  production: "Éles",
  test: "Teszt",
};

export function NavKapcsolat({
  allapot,
  canEdit,
}: {
  allapot: KapcsolatAllapot;
  canEdit: boolean;
}) {
  const router = useRouter();
  const [, startTransition] = useTransition();
  const [open, setOpen] = useState(!allapot.beallitva);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const [taxNumber, setTaxNumber] = useState(allapot.taxNumber ?? "");
  const [login, setLogin] = useState(allapot.login ?? "");
  const [password, setPassword] = useState("");
  const [signKey, setSignKey] = useState("");
  const [environment, setEnvironment] = useState(allapot.environment);

  const run = (fn: () => Promise<{ ok: true; message?: string } | { ok: false; error: string }>) => {
    setError(null);
    setMessage(null);
    setBusy(true);
    startTransition(async () => {
      const result = await fn();
      setBusy(false);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      setMessage(result.message ?? null);
      setPassword("");
      setSignKey("");
      router.refresh();
    });
  };

  if (!canEdit) {
    return (
      <div className="card card-pad">
        <h2 className="card-title">NAV-kapcsolat</h2>
        {/* Deliberately says nothing about whether it is configured: the
            credential row is invisible to an előadó, so any claim here would
            be a guess dressed up as a status. */}
        <p className="mt-2 text-sm text-slate-500">
          A NAV technikai felhasználót a cég tulajdonosa vagy adminisztrátora állítja be.
          Az eredményeket alább te is látod.
        </p>
      </div>
    );
  }

  return (
    <div className="card card-pad space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="card-title">NAV-kapcsolat</h2>
          <p className="mt-1 text-sm text-slate-500">
            {allapot.beallitva ? (
              <>
                {ENV_LABEL[allapot.environment]} · {allapot.taxNumber} · {allapot.login}
                {allapot.lastOkAt ? (
                  <> · utoljára működött: {allapot.lastOkAt.slice(0, 10)}</>
                ) : (
                  <> · még nem volt sikeres lekérdezés</>
                )}
              </>
            ) : (
              "Az Online Számla portálon létrehozott technikai felhasználó adatai."
            )}
          </p>
        </div>
        <div className="flex gap-2">
          {allapot.beallitva && (
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              disabled={busy}
              onClick={() => run(probaNavKapcsolat)}
            >
              Kapcsolat tesztelése
            </button>
          )}
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            onClick={() => setOpen((v) => !v)}
          >
            {open ? "Bezárás" : allapot.beallitva ? "Módosítás" : "Beállítás"}
          </button>
        </div>
      </div>

      {allapot.lastError && !error && !message && (
        <p className="alert alert-warn">
          Az utolsó próbálkozás hibája: {allapot.lastError}
        </p>
      )}
      {error && <p className="alert alert-error">{error}</p>}
      {message && <p className="alert alert-info">{message}</p>}

      {open && (
        <form
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            run(() =>
              mentNavKapcsolat({ taxNumber, login, password, signKey, environment })
            );
          }}
        >
          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Adószám</span>
            <input
              className="control"
              value={taxNumber}
              onChange={(e) => setTaxNumber(e.target.value)}
              placeholder="12345678"
              inputMode="numeric"
            />
            <span className="mt-1 block text-xs text-slate-400">
              A cég törzsszáma. A 11 jegyű adószámot is beírhatod.
            </span>
          </label>

          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Technikai felhasználó neve</span>
            <input
              className="control"
              value={login}
              onChange={(e) => setLogin(e.target.value)}
              autoComplete="off"
            />
          </label>

          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Jelszó</span>
            <input
              className="control"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="new-password"
              placeholder={allapot.beallitva ? "változatlan" : ""}
            />
          </label>

          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Aláíró kulcs</span>
            <input
              className="control"
              type="password"
              value={signKey}
              onChange={(e) => setSignKey(e.target.value)}
              autoComplete="off"
              placeholder={allapot.beallitva ? "változatlan" : ""}
            />
            <span className="mt-1 block text-xs text-slate-400">
              Nem a jelszó. Az Online Számla portál külön adja a technikai felhasználóhoz.
            </span>
          </label>

          <label className="text-sm">
            <span className="mb-1 block text-slate-600">Környezet</span>
            <select
              className="control"
              value={environment}
              onChange={(e) => setEnvironment(e.target.value as "production" | "test")}
            >
              <option value="production">Éles (api.onlineszamla.nav.gov.hu)</option>
              <option value="test">Teszt (api-test.onlineszamla.nav.gov.hu)</option>
            </select>
          </label>

          <div className="flex items-end gap-2 sm:col-span-2">
            <button type="submit" className="btn btn-primary" disabled={busy}>
              Mentés
            </button>
            {allapot.beallitva && (
              <button
                type="button"
                className="btn btn-danger"
                disabled={busy}
                onClick={() => {
                  if (!confirm("Biztosan törlöd a NAV-kapcsolat adatait?")) return;
                  run(torolNavKapcsolat);
                }}
              >
                Kapcsolat törlése
              </button>
            )}
          </div>

          <p className="sm:col-span-2 text-xs text-slate-400">
            A jelszó és az aláíró kulcs titkosítva tárolódik, és soha nem jelenik meg újra a
            képernyőn — módosításnál üresen hagyva a korábbi érték marad. A technikai
            felhasználónak elég a <strong>Számlák lekérdezése</strong> jogosultság: ez az
            alkalmazás csak olvas a NAV-tól, adatot nem küld be.
          </p>
        </form>
      )}
    </div>
  );
}
