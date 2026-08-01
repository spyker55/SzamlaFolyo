"use client";

import Link from "next/link";
import { useActionState } from "react";
import { signIn, type AuthFormState } from "@/lib/auth/actions";
import { AuthShell } from "@/components/app/AuthShell";

const initialState: AuthFormState = { error: null };

export default function BejelentkezesPage() {
  const [state, formAction, pending] = useActionState(signIn, initialState);

  return (
    <AuthShell
      title="Bejelentkezés"
      subtitle="Lépj be a cég iktatókönyvéhez."
      footer={
        <>
          Nincs még fiókod?{" "}
          <Link href="/regisztracio" className="link">
            Regisztráció
          </Link>
        </>
      }
    >
      <form action={formAction} className="mt-6 space-y-4">
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
            Jelszó
          </label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
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
          {pending ? "Bejelentkezés…" : "Bejelentkezés"}
        </button>
      </form>
    </AuthShell>
  );
}
