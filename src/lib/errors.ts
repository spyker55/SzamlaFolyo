// Every server action ends with the same problem: something failed, and the
// user needs a sentence rather than a Postgres exception.
//
// The refusals a feature can predict are translated where they are raised —
// "different tax numbers", "ugy is irattarazott". This handles the ones no
// feature can predict, which are also the ones that actually happen most: the
// request never arriving. Those used to be pasted straight into the sentence,
// so a dropped wifi connection read "A mentés nem sikerült: TypeError: fetch
// failed", which looks like the app broke rather than like the network did.
//
// Whether the write happened matters more than why it failed, so each message
// says so where it can be said honestly. A network failure is the one case
// where we know nothing was written: the request never left.

const NETWORK =
  /fetch failed|failed to fetch|network ?error|econnreset|econnrefused|enotfound|etimedout|socket hang up|load failed/;
const SESSION = /jwt|not authenticated|invalid claim|token is expired|no api key/;
const FORBIDDEN = /row-level security|permission denied|insufficient privilege|must be owner/;
const TIMEOUT = /statement timeout|canceling statement|query timeout/;
const CONFLICT = /deadlock detected|could not serialize|lock timeout/;

export function hunSupabaseError(message: string, fallback: string): string {
  const m = message.toLowerCase();

  if (NETWORK.test(m)) {
    return "Nem sikerült elérni a szervert. Ellenőrizd az internetkapcsolatot — a művelet nem történt meg, nyugodtan próbáld újra.";
  }
  if (SESSION.test(m)) {
    return "A munkameneted lejárt. Jelentkezz be újra, és próbáld meg még egyszer.";
  }
  if (FORBIDDEN.test(m)) {
    return "Ehhez a művelethez nincs jogosultságod.";
  }
  if (TIMEOUT.test(m)) {
    return "A művelet túl sokáig tartott, és megszakadt. Próbáld újra — ha ismétlődik, szólj.";
  }
  if (CONFLICT.test(m)) {
    return "Valaki más éppen ugyanezen dolgozott. Próbáld meg újra.";
  }

  // Nothing recognised. The technical text goes in brackets rather than into
  // the sentence: it is for the bug report, not for the reader.
  return `${fallback} (${message})`;
}

// The browser half of the same question, for the two places that talk to our
// own API routes with fetch(). navigator.onLine is only trustworthy when it
// says false, which is exactly how it is used here.
export function hunFetchError(fallback: string): string {
  if (typeof navigator !== "undefined" && navigator.onLine === false) {
    return "Nincs internetkapcsolat. Csatlakozz újra, és próbáld meg még egyszer.";
  }
  return fallback;
}
