// Reading and writing the Online Számla API's XML, deliberately narrowly.
//
// This is a parser for one machine's output, not for the web. It understands
// elements, attributes, text, CDATA, comments and the five predefined
// entities, and it **throws on everything else** — a DOCTYPE, an unknown
// entity, a mismatched close tag, trailing content after the root. That
// strictness is the point rather than an omission: the failure mode of a
// lenient parser here is silently dropping an <invoiceDigest> element, and a
// dropped digest does not look like a bug. It looks like an invoice the NAV
// does not know about, which is exactly the alarm this feature exists to
// raise. A parser that loses data quietly would make the feature lie.
//
// Namespace prefixes are stripped rather than resolved. NAV moves elements
// between the OSA and the NTCA-common namespaces across versions and the
// generated prefixes vary by endpoint (ns2:, common:, none); the local names
// are unambiguous within a response, so prefix-stripping reads more responses
// correctly than prefix-matching would.

export type XmlNode = {
  name: string;
  attrs: Record<string, string>;
  children: XmlNode[];
  text: string;
};

const NAMED_ENTITY: Record<string, string> = {
  amp: "&",
  lt: "<",
  gt: ">",
  quot: '"',
  apos: "'",
};

function decodeEntities(raw: string): string {
  if (!raw.includes("&")) return raw;

  let out = "";
  let i = 0;
  while (i < raw.length) {
    const amp = raw.indexOf("&", i);
    if (amp < 0) {
      out += raw.slice(i);
      break;
    }
    out += raw.slice(i, amp);
    const semi = raw.indexOf(";", amp);
    if (semi < 0 || semi - amp > 12) {
      throw new Error("XML: lezáratlan entitás");
    }
    const body = raw.slice(amp + 1, semi);
    if (body.startsWith("#x") || body.startsWith("#X")) {
      const code = Number.parseInt(body.slice(2), 16);
      if (!Number.isInteger(code) || code < 0 || code > 0x10ffff) {
        throw new Error(`XML: hibás karakterhivatkozás: &${body};`);
      }
      out += String.fromCodePoint(code);
    } else if (body.startsWith("#")) {
      const code = Number.parseInt(body.slice(1), 10);
      if (!Number.isInteger(code) || code < 0 || code > 0x10ffff) {
        throw new Error(`XML: hibás karakterhivatkozás: &${body};`);
      }
      out += String.fromCodePoint(code);
    } else {
      const named = NAMED_ENTITY[body];
      if (named === undefined) {
        throw new Error(`XML: ismeretlen entitás: &${body};`);
      }
      out += named;
    }
    i = semi + 1;
  }
  return out;
}

function localName(qualified: string): string {
  const colon = qualified.lastIndexOf(":");
  return colon < 0 ? qualified : qualified.slice(colon + 1);
}

const ATTR = /\s*([A-Za-z_:][A-Za-z0-9_.:-]*)\s*=\s*("([^"]*)"|'([^']*)')/y;

function parseAttrs(body: string): Record<string, string> {
  const attrs: Record<string, string> = {};
  ATTR.lastIndex = 0;
  let at = 0;
  while (at < body.length) {
    if (/^\s*$/.test(body.slice(at))) break;
    ATTR.lastIndex = at;
    const m = ATTR.exec(body);
    if (!m) throw new Error(`XML: értelmezhetetlen attribútum: ${body.slice(at, at + 40)}`);
    attrs[localName(m[1])] = decodeEntities(m[3] ?? m[4] ?? "");
    at = ATTR.lastIndex;
  }
  return attrs;
}

export function parseXml(input: string): XmlNode {
  const src = input.charCodeAt(0) === 0xfeff ? input.slice(1) : input;
  const stack: XmlNode[] = [];
  let root: XmlNode | null = null;
  let i = 0;

  while (i < src.length) {
    const lt = src.indexOf("<", i);
    if (lt < 0) {
      if (src.slice(i).trim() !== "") throw new Error("XML: szöveg a gyökérelem után");
      break;
    }

    const between = src.slice(i, lt);
    if (between !== "") {
      const current = stack[stack.length - 1];
      if (current) current.text += decodeEntities(between);
      else if (between.trim() !== "") throw new Error("XML: szöveg a gyökérelemen kívül");
    }

    // <?xml …?> and any other processing instruction, and <!-- … -->.
    if (src.startsWith("<?", lt)) {
      const end = src.indexOf("?>", lt);
      if (end < 0) throw new Error("XML: lezáratlan feldolgozási utasítás");
      i = end + 2;
      continue;
    }
    if (src.startsWith("<!--", lt)) {
      const end = src.indexOf("-->", lt);
      if (end < 0) throw new Error("XML: lezáratlan megjegyzés");
      i = end + 3;
      continue;
    }
    if (src.startsWith("<![CDATA[", lt)) {
      const end = src.indexOf("]]>", lt);
      if (end < 0) throw new Error("XML: lezáratlan CDATA");
      const current = stack[stack.length - 1];
      if (!current) throw new Error("XML: CDATA a gyökérelemen kívül");
      current.text += src.slice(lt + 9, end);
      i = end + 3;
      continue;
    }
    if (src.startsWith("<!", lt)) {
      // A DOCTYPE has no business in an API response, and accepting one is how
      // entity-expansion attacks get in. Nothing NAV sends contains one.
      throw new Error("XML: nem támogatott deklaráció (<!…>)");
    }

    const gt = src.indexOf(">", lt);
    if (gt < 0) throw new Error("XML: lezáratlan elem");

    if (src.startsWith("</", lt)) {
      const name = localName(src.slice(lt + 2, gt).trim());
      const open = stack.pop();
      if (!open) throw new Error(`XML: felesleges záró elem: ${name}`);
      if (open.name !== name) {
        throw new Error(`XML: nem illeszkedő záró elem: ${name} (nyitva: ${open.name})`);
      }
      i = gt + 1;
      continue;
    }

    let inner = src.slice(lt + 1, gt);
    const selfClosing = inner.endsWith("/");
    if (selfClosing) inner = inner.slice(0, -1);

    const space = inner.search(/\s/);
    const rawName = space < 0 ? inner : inner.slice(0, space);
    if (rawName === "") throw new Error("XML: névtelen elem");

    const node: XmlNode = {
      name: localName(rawName),
      attrs: space < 0 ? {} : parseAttrs(inner.slice(space)),
      children: [],
      text: "",
    };

    const parent = stack[stack.length - 1];
    if (parent) parent.children.push(node);
    else if (root) throw new Error("XML: több gyökérelem");
    else root = node;

    if (!selfClosing) stack.push(node);
    i = gt + 1;
  }

  if (stack.length > 0) {
    throw new Error(`XML: lezáratlan elem: ${stack[stack.length - 1].name}`);
  }
  if (!root) throw new Error("XML: nincs gyökérelem");
  return root;
}

// Readers ---------------------------------------------------------------

export function el(node: XmlNode | null | undefined, ...path: string[]): XmlNode | null {
  let current: XmlNode | null | undefined = node;
  for (const name of path) {
    current = current?.children.find((c) => c.name === name) ?? null;
    if (!current) return null;
  }
  return current ?? null;
}

export function els(node: XmlNode | null | undefined, name: string): XmlNode[] {
  return node?.children.filter((c) => c.name === name) ?? [];
}

export function txt(node: XmlNode | null | undefined, ...path: string[]): string | null {
  const found = path.length === 0 ? (node ?? null) : el(node, ...path);
  if (!found) return null;
  const value = found.text.trim();
  return value === "" ? null : value;
}

export function num(node: XmlNode | null | undefined, ...path: string[]): number | null {
  const raw = txt(node, ...path);
  if (raw === null) return null;
  const n = Number(raw);
  return Number.isFinite(n) ? n : null;
}

export function bool(node: XmlNode | null | undefined, ...path: string[]): boolean | null {
  const raw = txt(node, ...path);
  if (raw === null) return null;
  if (raw === "true" || raw === "1") return true;
  if (raw === "false" || raw === "0") return false;
  return null;
}

// Writing ---------------------------------------------------------------

export function escapeXml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

export function tag(name: string, value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === "") return "";
  return `<${name}>${escapeXml(String(value))}</${name}>`;
}
