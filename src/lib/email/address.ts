// Every company receives at <inbox_token>@<INBOX_DOMAIN>. A subdomain is used
// so the MX records never touch the main domain's mail.

export const INBOX_DOMAIN = process.env.EMAIL_INBOX_DOMAIN ?? "iktato.szamlafolyo.hu";

export function inboxAddress(token: string, domain: string = INBOX_DOMAIN): string {
  return `${token}@${domain}`;
}

// Pulls the bare address out of "Név <a@b.hu>" or "a@b.hu".
export function normalizeAddress(raw: string): string | null {
  const angled = raw.match(/<([^>]+)>/);
  const address = (angled ? angled[1] : raw).trim().toLowerCase();
  return address.includes("@") ? address : null;
}

// A message may be addressed to several people, only one of which is us — and
// it may carry plus-addressing (token+valami@...). Returns the first token
// belonging to our inbox domain.
export function findInboxToken(
  recipients: string[],
  domain: string = INBOX_DOMAIN
): string | null {
  const wanted = domain.toLowerCase();

  for (const raw of recipients) {
    const address = normalizeAddress(raw);
    if (!address) continue;

    const at = address.lastIndexOf("@");
    if (address.slice(at + 1) !== wanted) continue;

    const local = address.slice(0, at).split("+")[0];
    if (local.length > 0) return local;
  }
  return null;
}
