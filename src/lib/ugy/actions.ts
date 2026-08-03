"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { hunSupabaseError } from "@/lib/errors";
import { canTransition, isUgyStatus } from "@/lib/ugy/status";
import { irattariJel as jelOf } from "@/lib/irattar/terv";

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
  // The irattári jel is no longer typed by hand: it is derived from the chosen
  // irattári tétel, because the mark only means something if something reads
  // it, and what reads it is the retention calculation.
  irattariTetelId: string | null;
  eloadoUserId: string | null;
};

export async function mentMetaadat(ugyId: string, meta: UgyMeta): Promise<UgyResult> {
  const targy = meta.targy.trim();
  if (targy === "") return { ok: false, error: "A tárgy nem lehet üres." };
  if (meta.hatarido !== null && !/^\d{4}-\d{2}-\d{2}$/.test(meta.hatarido)) {
    return { ok: false, error: "Érvénytelen határidő." };
  }

  const supabase = await createSupabaseServerClient();

  // The jel is written next to the link rather than derived on read: it is
  // what the iktatókönyv printed on that day, and a column that re-rendered
  // whenever the terv is edited would make the register describe a past it
  // did not have.
  //
  // The awkward case is an ügy whose jel was typed by hand before the terv
  // existed. The select shows "nincs besorolva" for it, so saving an unrelated
  // field — a corrected tárgy — would silently wipe text somebody wrote. So
  // the jel is only cleared when a classification is actually being taken
  // away, never as a side effect of leaving the field alone.
  const jel: { irattari_jel?: string | null } = {};

  if (meta.irattariTetelId) {
    const { data: tetel } = await supabase
      .from("irattari_tetel")
      .select("tetelszam, orzesi_ido_ev")
      .eq("id", meta.irattariTetelId)
      .maybeSingle();
    if (!tetel) return { ok: false, error: "Ez az irattári tétel nem található." };
    jel.irattari_jel = jelOf(tetel.tetelszam as string, tetel.orzesi_ido_ev as number | null);
  } else {
    const { data: current } = await supabase
      .from("ugy")
      .select("irattari_tetel_id")
      .eq("id", ugyId)
      .maybeSingle();
    if (current?.irattari_tetel_id) jel.irattari_jel = null;
  }

  const { data, error } = await supabase
    .from("ugy")
    .update({
      targy,
      hatarido: meta.hatarido,
      irattari_tetel_id: meta.irattariTetelId,
      ...jel,
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
  if (message.includes("belongs to another company")) {
    return "Ez az irattári tétel egy másik céghez tartozik.";
  }
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
