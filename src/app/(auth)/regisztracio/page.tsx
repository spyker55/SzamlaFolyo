"use client";

import Link from "next/link";
import { useActionState } from "react";
import { signUp, type AuthFormState } from "@/lib/auth/actions";
import { AuthShell } from "@/components/app/AuthShell";

const initialState: AuthFormState = { error: null };

export default function RegisztracioPage() {
  const [state, formAction, pending] = useActionState(signUp, initialState);

  return (
    <AuthShell
      title="Regisztráció"
      subtitle="Néhány adat, és kezdheted az iktatást."
      footer={
        <>
          Van már fiókod?{" "}
          <Link href="/bejelentkezes" className="link">
            Bejelentkezés
          </Link>
        </>
      }
    >
      {state.info ? (
        <p className="alert mt-6 border-emerald-200 bg-emerald-50 text-emerald-800">
          {state.info}
        </p>
      ) : (
        <form action={formAction} className="mt-6 space-y-4">
          <div>
            <label htmlFor="full_name" className="flabel">
              Teljes név
            </label>
            <input
              id="full_name"
              name="full_name"
              type="text"
              autoComplete="name"
              required
              className="control py-2"
            />
          </div>
          <div>
            <label htmlFor="email" className="flabel">
              E-mail cím
            </label>
            <input
              id="email"
              name="email"
              type="email"
              autoComplete="email"
              required
              className="control py-2"
            />
          </div>
          <div>
            <label htmlFor="password" className="flabel">
              Jelszó (legalább 8 karakter)
            </label>
            <input
              id="password"
              name="password"
              type="password"
              autoComplete="new-password"
              minLength={8}
              required
              className="control py-2"
            />
          </div>

          {state.error && (
            <p className="alert alert-error" role="alert">
              {state.error}
            </p>
          )}

          <button type="submit" disabled={pending} className="btn btn-primary w-full py-2">
            {pending ? "Regisztráció…" : "Regisztráció"}
          </button>
        </form>
      )}
    </AuthShell>
  );
}
