"use client";

import { useActionState } from "react";
import { createCompany, type AuthFormState } from "@/lib/auth/actions";

const initialState: AuthFormState = { error: null };

export default function OnboardingPage() {
  const [state, formAction, pending] = useActionState(
    createCompany,
    initialState
  );

  return (
    <main className="flex flex-1 items-center justify-center p-6">
      <div className="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
        <h1 className="text-2xl font-semibold">Cég létrehozása</h1>
        <p className="mt-1 text-sm text-gray-500">
          Add meg a céget, amelynek az iratait iktatni fogod.
        </p>

        <form action={formAction} className="mt-6 space-y-4">
          <div>
            <label htmlFor="name" className="block text-sm font-medium">
              Cégnév
            </label>
            <input
              id="name"
              name="name"
              type="text"
              required
              className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
            />
          </div>
          <div>
            <label htmlFor="tax_number" className="block text-sm font-medium">
              Adószám (nem kötelező)
            </label>
            <input
              id="tax_number"
              name="tax_number"
              type="text"
              placeholder="12345678-2-42"
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
            {pending ? "Létrehozás…" : "Cég létrehozása"}
          </button>
        </form>
      </div>
    </main>
  );
}
