"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { hunSupabaseError } from "@/lib/errors";
import { requireMembership } from "@/lib/tenant";
import { encryptSecret, navSecretKeyConfigured } from "@/lib/nav/secret";
import { navErrorMessage } from "@/lib/nav/client";
import { navSoftware } from "@/lib/nav/config";
import { loadCredentialRow, runSync, testCredentials } from "@/lib/nav/sync";
import { splitDateRange, type NavDirection } from "@/lib/nav/query";

export type NavResult = { ok: true; message?: string } | { ok: false; error: string };

export type NavCredentialInput = {
  taxNumber: string;
  login: string;
  password: string;
  signKey: string;
  environment: "production" | "test";
};

// NAV's <taxNumber> takes the 8-digit törzsszám. People paste what is printed
// on the invoice, which is the 11-digit adószám, so the first eight digits are
// taken rather than the paste refused.
function torzsszam(raw: string): string | null {
  const digits = raw.replace(/[^0-9]/g, "");
  if (digits.length !== 8 && digits.length !== 11) return null;
  return digits.slice(0, 8);
}

export async function mentNavKapcsolat(input: NavCredentialInput): Promise<NavResult> {
  if (!navSecretKeyConfigured()) {
    return {
      ok: false,
      error:
        "A NAV_SECRET_KEY környezeti változó nincs beállítva, enélkül a jelszót nem tudjuk " +
        "titkosítva tárolni — és titkosítás nélkül nem tároljuk el.",
    };
  }

  const software = navSoftware();
  if (!software.ok) return { ok: false, error: software.error };

  const taxNumber = torzsszam(input.taxNumber);
  if (!taxNumber) return { ok: false, error: "Az adószám 8 vagy 11 számjegy legyen." };

  const login = input.login.trim();
  if (login === "") return { ok: false, error: "A technikai felhasználó neve kötelező." };

  const { companyId } = await requireMembership();
  const supabase = await createSupabaseServerClient();
  const existing = await loadCredentialRow(supabase);

  const password = input.password.trim();
  const signKey = input.signKey.trim();

  // On an edit, an empty secret field means "leave it alone" — the stored
  // value is never sent to the browser, so the form cannot echo it back and an
  // empty box has to mean unchanged rather than erased.
  if (!existing && (password === "" || signKey === "")) {
    return { ok: false, error: "A jelszó és az aláíró kulcs is kell az első beállításhoz." };
  }

  const payload: Record<string, unknown> = {
    company_id: companyId,
    tax_number: taxNumber,
    login,
    environment: input.environment,
  };
  if (password !== "") payload.password_enc = encryptSecret(password, companyId);
  if (signKey !== "") payload.sign_key_enc = encryptSecret(signKey, companyId);

  const { error } = existing
    ? await supabase.from("nav_credential").update(payload).eq("id", existing.id)
    : await supabase.from("nav_credential").insert(payload);

  if (error) return { ok: false, error: hunNavError(error.message) };

  revalidatePath("/nav");
  return { ok: true };
}

export async function torolNavKapcsolat(): Promise<NavResult> {
  const supabase = await createSupabaseServerClient();
  const existing = await loadCredentialRow(supabase);
  if (!existing) return { ok: true };

  const { error } = await supabase.from("nav_credential").delete().eq("id", existing.id);
  if (error) return { ok: false, error: hunNavError(error.message) };

  revalidatePath("/nav");
  return { ok: true };
}

// Proves the four secrets, the software identification and the technical
// user's permission in one go, by asking for a single page of the same digest
// query the egyeztetés runs. A wrong tax number cannot slip through it either:
// NAV rejects a login that does not belong to the taxpayer in the request.
export async function probaNavKapcsolat(): Promise<NavResult> {
  const supabase = await createSupabaseServerClient();
  const existing = await loadCredentialRow(supabase);
  if (!existing) return { ok: false, error: "Előbb mentsd el a kapcsolat adatait." };

  try {
    const probe = await testCredentials(existing);
    await supabase
      .from("nav_credential")
      .update({ last_ok_at: new Date().toISOString(), last_error: null })
      .eq("id", existing.id);
    revalidatePath("/nav");

    return {
      ok: true,
      message:
        probe.count === 0
          ? "A kapcsolat működik. Az elmúlt hétben a NAV egyetlen bejövő számláról sem tud."
          : `A kapcsolat működik. Az elmúlt hétben a NAV ${probe.count} bejövő számláról tud.`,
    };
  } catch (err) {
    const message = navErrorMessage(err);
    await supabase
      .from("nav_credential")
      .update({ last_error: message.slice(0, 500) })
      .eq("id", existing.id);
    revalidatePath("/nav");
    return { ok: false, error: message };
  }
}

export async function futtatNavEgyeztetes(input: {
  direction: NavDirection;
  from: string;
  to: string;
}): Promise<NavResult> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.from) || !/^\d{4}-\d{2}-\d{2}$/.test(input.to)) {
    return { ok: false, error: "Érvénytelen időszak." };
  }
  if (input.from > input.to) {
    return { ok: false, error: "A kezdő dátum nem lehet későbbi a záró dátumnál." };
  }

  const windows = splitDateRange(input.from, input.to);
  if (windows.length > 24) {
    return {
      ok: false,
      error: "Ez az időszak túl hosszú egy lekérdezéshez (a NAV 35 naponként enged kérdezni). Válassz rövidebbet.",
    };
  }

  const { userId } = await requireMembership();
  const supabase = await createSupabaseServerClient();
  const row = await loadCredentialRow(supabase);
  if (!row) {
    return { ok: false, error: "Nincs beállítva NAV-kapcsolat ehhez a céghez." };
  }

  try {
    const outcome = await runSync(supabase, {
      row,
      direction: input.direction,
      from: input.from,
      to: input.to,
      userId,
    });
    revalidatePath("/nav");
    return {
      ok: true,
      message:
        outcome.count === 0
          ? "A NAV ebben az időszakban egyetlen számláról sem tud."
          : `${outcome.count} számla a NAV-tól, ebből ${outcome.newCount} új.`,
    };
  } catch (err) {
    revalidatePath("/nav");
    return { ok: false, error: navErrorMessage(err) };
  }
}

function hunNavError(message: string): string {
  if (message.includes("nav_credential_tax_number_shape")) {
    return "Az adószám 8 számjegy (törzsszám) legyen.";
  }
  if (message.includes("nav_credential_company_id_key") || message.includes("duplicate key")) {
    return "Ehhez a céghez már tartozik NAV-kapcsolat.";
  }
  if (message.includes("row-level security") || message.includes("violates row-level")) {
    return "A NAV-kapcsolatot csak tulajdonos vagy adminisztrátor állíthatja be.";
  }
  return hunSupabaseError(message, "A mentés nem sikerült.");
}
