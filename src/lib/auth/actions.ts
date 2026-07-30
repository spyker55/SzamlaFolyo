"use server";

import { redirect } from "next/navigation";
import { createSupabaseServerClient } from "@/lib/supabase/server";

export type AuthFormState = { error: string | null; info?: string | null };

export async function signIn(
  _prev: AuthFormState,
  formData: FormData
): Promise<AuthFormState> {
  const email = String(formData.get("email") ?? "").trim();
  const password = String(formData.get("password") ?? "");

  if (!email || !password) {
    return { error: "Add meg az e-mail címet és a jelszót." };
  }

  const supabase = await createSupabaseServerClient();
  const { error } = await supabase.auth.signInWithPassword({ email, password });

  if (error) {
    return { error: "Hibás e-mail cím vagy jelszó." };
  }

  redirect("/inbox");
}

export async function signUp(
  _prev: AuthFormState,
  formData: FormData
): Promise<AuthFormState> {
  const email = String(formData.get("email") ?? "").trim();
  const password = String(formData.get("password") ?? "");
  const fullName = String(formData.get("full_name") ?? "").trim();

  if (!email || !password || !fullName) {
    return { error: "Minden mező kitöltése kötelező." };
  }
  if (password.length < 8) {
    return { error: "A jelszó legalább 8 karakter legyen." };
  }

  const supabase = await createSupabaseServerClient();
  const { data, error } = await supabase.auth.signUp({
    email,
    password,
    options: { data: { full_name: fullName } },
  });

  if (error) {
    return { error: "A regisztráció nem sikerült: " + error.message };
  }

  // With email confirmation enabled there is no session yet.
  if (!data.session) {
    return {
      error: null,
      info: "Elküldtük a megerősítő e-mailt. A benne lévő linkkel tudsz belépni.",
    };
  }

  redirect("/onboarding");
}

export async function signOut() {
  const supabase = await createSupabaseServerClient();
  await supabase.auth.signOut();
  redirect("/bejelentkezes");
}

export async function createCompany(
  _prev: AuthFormState,
  formData: FormData
): Promise<AuthFormState> {
  const name = String(formData.get("name") ?? "").trim();
  const taxNumber = String(formData.get("tax_number") ?? "").trim();

  if (!name) {
    return { error: "A cégnév kitöltése kötelező." };
  }

  const supabase = await createSupabaseServerClient();
  const { error } = await supabase.rpc("create_company_with_owner", {
    p_name: name,
    p_tax_number: taxNumber || null,
  });

  if (error) {
    if (error.message.includes("already belongs")) {
      redirect("/inbox");
    }
    return { error: "A cég létrehozása nem sikerült: " + error.message };
  }

  redirect("/inbox");
}
