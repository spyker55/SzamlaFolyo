"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { hunSupabaseError } from "@/lib/errors";
import { canTransition, isUgyStatus } from "@/lib/ugy/status";

export type UgyResult = { ok: true } | { ok: false; error: string };

// app.protect_ugy() is the guarantee; these checks only make the refusal
// arrive as Hungarian prose instead of a Postgres exception.
export async function valtoztatStatus(
  ugyId: string,
  from: string,
  to: string
): Promise<UgyResult> {
  if (!isUgyStatus(from) || !isUgyStatus(to)) {
    return { ok: false, error: "Ismeretlen ügyállapot." };
  }
  if (!canTransition(from, to)) {
    return { ok: false, error: "Ebből az állapotból ez a lépés nem lehetséges." };
  }

  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase
    .from("ugy")
    .update({ status: to })
    // The status we were shown has to still be the status in the database,
    // or someone else moved the ugy while this page was open and the button
    // would apply a transition the user never saw.
    .eq("id", ugyId)
    .eq("status", from)
    .select("id")
    .maybeSingle();

  if (error) return { ok: false, error: hunUgyError(error.message) };
  if (!data) {
    return {
      ok: false,
      error: "Az ügy állapota közben megváltozott. Frissítsd az oldalt.",
    };
  }

  revalidatePath("/ugyek");
  revalidatePath(`/ugyek/${ugyId}`);
  revalidatePath("/iktatokonyv");
  return { ok: true };
}

export type UgyMeta = {
  targy: string;
  hatarido: string | null;
  irattariJel: string;
  eloadoUserId: string | null;
};

export async function mentMetaadat(ugyId: string, meta: UgyMeta): Promise<UgyResult> {
  const targy = meta.targy.trim();
  if (targy === "") return { ok: false, error: "A tárgy nem lehet üres." };
  if (meta.hatarido !== null && !/^\d{4}-\d{2}-\d{2}$/.test(meta.hatarido)) {
    return { ok: false, error: "Érvénytelen határidő." };
  }

  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase
    .from("ugy")
    .update({
      targy,
      hatarido: meta.hatarido,
      irattari_jel: meta.irattariJel.trim() || null,
      eloado_user_id: meta.eloadoUserId,
    })
    .eq("id", ugyId)
    .select("id")
    .maybeSingle();

  if (error) return { ok: false, error: hunUgyError(error.message) };
  if (!data) return { ok: false, error: "Ez az ügy nem található." };

  revalidatePath("/ugyek");
  revalidatePath(`/ugyek/${ugyId}`);
  revalidatePath("/iktatokonyv");
  return { ok: true };
}

function hunUgyError(message: string): string {
  if (message.includes("irattarazott")) {
    return "Az ügy irattárazott. Előbb vedd ki az irattárból, utána szerkeszthető.";
  }
  if (message.includes("identity is immutable")) {
    return "Az ügy főszáma és éve nem módosítható.";
  }
  if (message.includes("status cannot go")) {
    return "Ebből az állapotból ez a lépés nem lehetséges.";
  }
  return hunSupabaseError(message, "A mentés nem sikerült.");
}
