"use server";

import { revalidatePath } from "next/cache";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { hunSupabaseError } from "@/lib/errors";
import { requireMembership } from "@/lib/tenant";
import { checkBankAccount, normalizeBankAccount } from "@/lib/partner/bank-account";
import { checkTaxNumber } from "@/lib/partner/identity";

export type PartnerResult = { ok: true; id?: string } | { ok: false; error: string };

export type PartnerFields = {
  name: string;
  taxNumber: string;
  euTaxNumber: string;
  bankAccount: string;
  address: string;
  email: string;
  country: string;
  isSupplier: boolean;
  isCustomer: boolean;
  paymentTermDays: string;
  note: string;
};

type PartnerRow = {
  name: string;
  tax_number: string | null;
  eu_tax_number: string | null;
  bank_account: string | null;
  address: string | null;
  email: string | null;
  country: string | null;
  is_supplier: boolean;
  is_customer: boolean;
  default_payment_term_days: number | null;
  note: string | null;
};

type Validated = { ok: true; row: PartnerRow } | { ok: false; error: string };

function validate(fields: PartnerFields): Validated {
  const name = fields.name.trim();
  if (name === "") return { ok: false, error: "A partner neve nem lehet üres." };

  const tax = checkTaxNumber(fields.taxNumber);
  if (!tax.ok) return { ok: false, error: tax.message };

  const bank = checkBankAccount(fields.bankAccount);
  if (!bank.ok) return { ok: false, error: bank.message };

  let paymentTerm: number | null = null;
  const rawTerm = fields.paymentTermDays.trim();
  if (rawTerm !== "") {
    const parsed = Number(rawTerm);
    if (!Number.isInteger(parsed) || parsed < 0 || parsed > 365) {
      return { ok: false, error: "A fizetési határidő 0 és 365 nap között lehet." };
    }
    paymentTerm = parsed;
  }

  const email = fields.email.trim();
  if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return { ok: false, error: "Az e-mail cím nem érvényes." };
  }

  return {
    ok: true,
    row: {
      name,
      // Stored the way it is written on the irat, not normalized: the register
      // records what the document said. Comparison is what normalizes.
      tax_number: fields.taxNumber.trim() || null,
      eu_tax_number: fields.euTaxNumber.trim().toUpperCase() || null,
      // The account number is the exception — it is dialled into a bank form,
      // so the separators are noise and only the digits matter.
      bank_account: normalizeBankAccount(fields.bankAccount) || null,
      address: fields.address.trim() || null,
      email: email || null,
      country: fields.country.trim().toUpperCase() || null,
      is_supplier: fields.isSupplier,
      is_customer: fields.isCustomer,
      default_payment_term_days: paymentTerm,
      note: fields.note.trim() || null,
    },
  };
}

export async function mentPartner(
  partnerId: string,
  fields: PartnerFields
): Promise<PartnerResult> {
  const validated = validate(fields);
  if (!validated.ok) return validated;

  const supabase = await createSupabaseServerClient();
  const { data, error } = await supabase
    .from("partner")
    .update(validated.row)
    .eq("id", partnerId)
    .select("id")
    .maybeSingle();

  if (error) return { ok: false, error: hunPartnerError(error.message) };
  if (!data) return { ok: false, error: "Ez a partner nem található." };

  revalidatePath("/partnerek");
  revalidatePath(`/partnerek/${partnerId}`);
  return { ok: true, id: partnerId };
}

export async function letrehozPartner(fields: PartnerFields): Promise<PartnerResult> {
  const validated = validate(fields);
  if (!validated.ok) return validated;

  const membership = await requireMembership();
  const supabase = await createSupabaseServerClient();

  const { data, error } = await supabase
    .from("partner")
    .insert({ ...validated.row, company_id: membership.companyId })
    .select("id")
    .maybeSingle();

  if (error) return { ok: false, error: hunPartnerError(error.message) };
  if (!data) return { ok: false, error: "A partner létrehozása nem sikerült." };

  revalidatePath("/partnerek");
  return { ok: true, id: data.id };
}

// The merge itself lives in the database, because repointing the iratok and
// retiring the partner have to be one transaction. This only carries the
// refusal back in Hungarian.
export async function osszevonPartner(
  survivorId: string,
  loserId: string
): Promise<PartnerResult> {
  const supabase = await createSupabaseServerClient();
  const { error } = await supabase.rpc("merge_partner", {
    p_survivor_id: survivorId,
    p_loser_id: loserId,
  });

  if (error) return { ok: false, error: hunPartnerError(error.message) };

  revalidatePath("/partnerek");
  revalidatePath(`/partnerek/${survivorId}`);
  revalidatePath(`/partnerek/${loserId}`);
  revalidatePath("/iktatokonyv");
  revalidatePath("/ugyek");
  return { ok: true, id: survivorId };
}

export async function visszavonOsszevonas(mergeId: string): Promise<PartnerResult> {
  const supabase = await createSupabaseServerClient();
  const { error } = await supabase.rpc("unmerge_partner", { p_merge_id: mergeId });

  if (error) return { ok: false, error: hunPartnerError(error.message) };

  revalidatePath("/partnerek");
  revalidatePath("/iktatokonyv");
  revalidatePath("/ugyek");
  return { ok: true };
}

function hunPartnerError(message: string): string {
  if (message.includes("different tax numbers")) {
    return "A két partner adószáma más törzsszámot hordoz — ez két külön cég, nem vonhatók össze.";
  }
  if (message.includes("requires owner or admin")) {
    return "Az összevonáshoz tulajdonosi vagy adminisztrátori jogosultság kell.";
  }
  if (message.includes("was merged into another")) {
    return "Ez a partner egy másikba lett olvasztva. Előbb vond vissza az összevonást.";
  }
  if (message.includes("already merged into another")) {
    return "Az egyik partner már össze van vonva egy harmadikkal.";
  }
  if (message.includes("already undone")) {
    return "Ezt az összevonást már visszavontad.";
  }
  if (message.includes("merged into itself") || message.includes("merges go through")) {
    return "Ez a lépés nem lehetséges.";
  }
  if (message.includes("identity is immutable")) {
    return "A partner céghez tartozása nem módosítható.";
  }
  if (message.includes("partner_company_tax_number_key")) {
    return "Ezzel az adószámmal már van partner. Vond össze a kettőt egy partnerré.";
  }
  if (message.includes("partner_company_name_norm_key")) {
    return "Ezzel a névvel már van adószám nélküli partner. Vond össze a kettőt.";
  }
  return hunSupabaseError(message, "A mentés nem sikerült.");
}
