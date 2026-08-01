"use client";

import { useActionState } from "react";
import { createCompany, type AuthFormState } from "@/lib/auth/actions";
import { AuthShell } from "@/components/app/AuthShell";

const initialState: AuthFormState = { error: null };

export default function OnboardingPage() {
  const [state, formAction, pending] = useActionState(
    createCompany,
    initialState
  );

  return (
    <AuthShell
      title="Cég létrehozása"
      subtitle="Add meg a céget, amelynek az iratait iktatni fogod."
    >
      <form action={formAction} className="mt-6 space-y-4">
        <div>
          <label htmlFor="name" className="flabel">
            Cégnév
          </label>
          <input id="name" name="name" type="text" required className="control py-2" />
        </div>
        <div>
          <label htmlFor="tax_number" className="flabel">
            Adószám (nem kötelező)
          </label>
          <input
            id="tax_number"
            name="tax_number"
            type="text"
            placeholder="12345678-2-42"
            className="control py-2"
          />
        </div>

        {state.error && (
          <p className="alert alert-error" role="alert">
            {state.error}
          </p>
        )}

        <button type="submit" disabled={pending} className="btn btn-primary w-full py-2">
          {pending ? "Létrehozás…" : "Cég létrehozása"}
        </button>
      </form>
    </AuthShell>
  );
}
