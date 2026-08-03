// Where the requests go, and who says they sent them.
//
// Every OSA 3.0 request carries a <software> block identifying the program
// making the call. It describes Számlafolyó, not the taxpayer, so it belongs
// in the environment rather than in a per-company row: one deployment, one
// answer. The developer's tax number is the only field with no honest default,
// and a request without it is rejected by NAV — so it is checked up front and
// reported as a setup problem instead of arriving as an unexplained schema
// validation error from Budapest.

export type NavEnvironment = "production" | "test";

export const NAV_ENDPOINT: Record<NavEnvironment, string> = {
  production: "https://api.onlineszamla.nav.gov.hu/invoiceService/v3",
  // Its own technical users and its own invoice data — nothing here crosses
  // over. Wrong environment produces an authentication failure, not wrong
  // numbers, which is the failure we want.
  test: "https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3",
};

export const NAV_REQUEST_VERSION = "3.0";
export const NAV_HEADER_VERSION = "1.0";

export type NavSoftware = {
  softwareId: string;
  softwareName: string;
  softwareOperation: "LOCAL_SOFTWARE" | "ONLINE_SERVICE";
  softwareMainVersion: string;
  softwareDevName: string;
  softwareDevContact: string;
  softwareDevCountryCode: string;
  softwareDevTaxNumber: string;
};

// [0-9A-Z\-]{18}, self-assigned — NAV does not issue it, it only requires that
// the same software always sends the same one.
const DEFAULT_SOFTWARE_ID = "SZAMLAFOLYO-000001";

export type SoftwareResult =
  | { ok: true; software: NavSoftware }
  | { ok: false; error: string };

export function navSoftware(
  env: Record<string, string | undefined> = process.env
): SoftwareResult {
  const softwareId = env.NAV_SOFTWARE_ID?.trim() || DEFAULT_SOFTWARE_ID;
  if (!/^[0-9A-Z-]{18}$/.test(softwareId)) {
    return {
      ok: false,
      error: "A NAV_SOFTWARE_ID pontosan 18 karakter lehet, csak nagybetű, szám és kötőjel.",
    };
  }

  const devTaxNumber = env.NAV_SOFTWARE_DEV_TAX_NUMBER?.trim() ?? "";
  if (!/^[0-9]{8}$/.test(devTaxNumber)) {
    return {
      ok: false,
      error:
        "A NAV_SOFTWARE_DEV_TAX_NUMBER nincs beállítva (a fejlesztő 8 jegyű törzsszáma). " +
        "A NAV minden kérésben megköveteli, enélkül a lekérdezés elutasításra kerül.",
    };
  }

  const devContact = env.NAV_SOFTWARE_DEV_CONTACT?.trim() ?? "";
  if (devContact === "") {
    return { ok: false, error: "A NAV_SOFTWARE_DEV_CONTACT nincs beállítva (fejlesztői e-mail cím)." };
  }

  return {
    ok: true,
    software: {
      softwareId,
      softwareName: env.NAV_SOFTWARE_NAME?.trim() || "Szamlafolyo",
      // The app runs as a service on szamlafolyo.hu; it is not installed on the
      // taxpayer's machine.
      softwareOperation: "ONLINE_SERVICE",
      softwareMainVersion: env.NAV_SOFTWARE_VERSION?.trim() || "1.0",
      softwareDevName: env.NAV_SOFTWARE_DEV_NAME?.trim() || "Szamlafolyo",
      softwareDevContact: devContact,
      softwareDevCountryCode: env.NAV_SOFTWARE_DEV_COUNTRY?.trim() || "HU",
      softwareDevTaxNumber: devTaxNumber,
    },
  };
}
