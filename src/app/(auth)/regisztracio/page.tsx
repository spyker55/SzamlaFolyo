"use client";

import Link from "next/link";
import { useActionState } from "react";
import { signUp, type AuthFormState } from "@/lib/auth/actions";

const initialState: AuthFormState = { error: null };

export default function RegisztracioPage() {
  const [state, formAction, pending] = useActionState(signUp, initialState);

  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
        <h1 className="text-2xl font-semibold">Számlafolyó</h1>
        <p className="mt-1 text-sm text-gray-500">Regisztráció</p>

        {state.info ? (
          <p className="mt-6 rounded-md bg-green-50 p-3 text-sm text-green-800">
            {state.info}
          </p>
        ) : (
          <form action={formAction} className="mt-6 space-y-4">
            <div>
              <label htmlFor="full_name" className="block text-sm font-medium">
                Teljes név
              </label>
              <input
                id="full_name"
                name="full_name"
                type="text"
                autoComplete="name"
                required
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
              />
            </div>
            <div>
              <label htmlFor="email" className="block text-sm font-medium">
                E-mail cím
              </label>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                required
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
              />
            </div>
            <div>
              <label htmlFor="password" className="block text-sm font-medium">
                Jelszó (legalább 8 karakter)
              </label>
              <input
                id="password"
                name="password"
                type="password"
                autoComplete="new-password"
                minLength={8}
                required
                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
              />
            </div>

            {state.error && (
              <p className="text-sm text-red-600" role="alert">
                {state.error}
              </p>
            )}

            <button
              type="submit"
              disabled={pending}
              className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {pending ? "Regisztráció…" : "Regisztráció"}
            </button>
          </form>
        )}

        <p className="mt-4 text-sm text-gray-500">
          Van már fiókod?{" "}
          <Link href="/bejelentkezes" className="text-blue-600 hover:underline">
            Bejelentkezés
          </Link>
        </p>
      </div>
    </main>
  );
}
