"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { hunSupabaseError } from "@/lib/errors";
import { validateTetel, type TetelInput } from "@/lib/irattar/terv";

export type IrattarResult = { ok: true } | { ok: false; error: string };

export async function ujTetel(input: TetelInput): Promise<IrattarResult> {
  const invalid = validateTetel(input);
  if (invalid) return { ok: false, error: invalid };

  const supabase = await createSupabaseServerClient();

  // The company comes from the membership, not from the form: a company_id in
  // a payload is a company_id somebody can change.
  const { data: member } = await supabase
    .from("company_member")
    .select("company_id")
    .limit(1)
    .maybeSingle();
  if (!member) return { ok: false, error: "Nincs cég a munkamenethez." };

  const { error } = await supabase.from("irattari_tetel").insert({
    company_id: member.company_id,
    tetelszam: input.tetelszam.trim(),
    nev: input.nev.trim(),
    orzesi_ido_ev: input.orzesiIdoEv,
    jogszabaly: input.jogszabaly.trim() || null,
    megjegyzes: input.megjegyzes.trim() || null,
  });

  if (error) return { ok: false, error: hunIrattarError(error.message) };

  revalidatePath("/irattari-terv");
  return { ok: true };
}

export async function mentTetel(
  tetelId: string,
  input: Omit<TetelInput, "tetelszam">
): Promise<IrattarResult> {
  // The tételszám is not editable — the guard trigger refuses it, and the form
  // does not offer it — so it is validated as already-valid here.
  const invalid = validateTetel({ ...input, tetelszam: "x" });
  if (invalid) return { ok: false, error: invalid };

  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase
    .from("irattari_tetel")
    .update({
      nev: input.nev.trim(),
      orzesi_ido_ev: input.orzesiIdoEv,
      jogszabaly: input.jogszabaly.trim() || null,
      megjegyzes: input.megjegyzes.trim() || null,
    })
    .eq("id", tetelId)
    .select("id")
    .maybeSingle();

  if (error) return { ok: false, error: hunIrattarError(error.message) };
  if (!data) return { ok: false, error: "Ez a tétel nem található, vagy nincs jogosultságod hozzá." };

  revalidatePath("/irattari-terv");
  revalidatePath("/ugyek");
  return { ok: true };
}

export async function valtoztatTetelAllapot(
  tetelId: string,
  aktiv: boolean
): Promise<IrattarResult> {
  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase
    .from("irattari_tetel")
    .update({ deleted_at: aktiv ? null : new Date().toISOString() })
    .eq("id", tetelId)
    .select("id")
    .maybeSingle();

  if (error) return { ok: false, error: hunIrattarError(error.message) };
  if (!data) return { ok: false, error: "Ez a tétel nem található, vagy nincs jogosultságod hozzá." };

  revalidatePath("/irattari-terv");
  return { ok: true };
}

function hunIrattarError(message: string): string {
  if (message.includes("irattari_tetel_tetelszam") || message.includes("duplicate key")) {
    return "Ilyen tételszám már van a tervben. Válassz másikat.";
  }
  if (message.includes("tetelszam of an irattari tetel cannot be changed")) {
    return "A tételszám nem módosítható. Inaktiváld a tételt, és vegyél fel újat.";
  }
  if (message.includes("orzesi_ido_sane")) {
    return "Az őrzési idő 1 és 100 év között lehet, vagy üres, ha nem selejtezhető.";
  }
  if (message.includes("row-level security") || message.includes("violates row-level")) {
    return "Az irattári tervet csak tulajdonos vagy adminisztrátor módosíthatja.";
  }
  return hunSupabaseError(message, "A mentés nem sikerült.");
}
