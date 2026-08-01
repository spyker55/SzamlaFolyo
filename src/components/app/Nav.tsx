"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  IconBook,
  IconDownload,
  IconFolder,
  IconHistory,
  IconInbox,
  IconUpload,
  IconUsers,
  IconWallet,
} from "@/components/ui/icons";
import { requestUpload } from "@/lib/ui/upload-intent";

type Item = {
  href: string;
  label: string;
  Icon: (props: { className?: string }) => React.ReactElement;
  // Routes that are not under href but belong to this entry: the review screen
  // lives at /ellenorzes/… and is reached only from the Beérkező, so the nav
  // should not go blank while the user is in it.
  also?: string[];
  badge?: number;
};

const SECTIONS: { title: string; items: Item[] }[] = [
  {
    title: "Iktatás",
    items: [
      { href: "/inbox", label: "Beérkező", Icon: IconInbox, also: ["/ellenorzes"] },
      { href: "/ugyek", label: "Ügyek", Icon: IconFolder },
      { href: "/iktatokonyv", label: "Iktatókönyv", Icon: IconBook },
    ],
  },
  {
    title: "Törzsadatok",
    items: [{ href: "/partnerek", label: "Partnerek", Icon: IconUsers }],
  },
  {
    title: "Pénzügy",
    items: [
      { href: "/fizetesek", label: "Fizetések", Icon: IconWallet },
      { href: "/export", label: "Könyvelői export", Icon: IconDownload },
    ],
  },
  {
    title: "Rendszer",
    items: [{ href: "/naplo", label: "Napló", Icon: IconHistory }],
  },
];

function isActive(pathname: string, item: Item): boolean {
  const prefixes = [item.href, ...(item.also ?? [])];
  return prefixes.some((p) => pathname === p || pathname.startsWith(`${p}/`));
}

function Logo() {
  return (
    <Link href="/inbox" className="flex items-center gap-2.5 px-3 py-1">
      <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-sm font-bold text-white">
        Sz
      </span>
      <span className="text-[15px] font-semibold tracking-tight text-slate-900">
        Számlafolyó
      </span>
    </Link>
  );
}

export function Sidebar({ inboxCount }: { inboxCount: number }) {
  const pathname = usePathname();

  return (
    <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col gap-1 border-r border-slate-200 bg-white px-3 py-4 lg:flex">
      <Logo />

      {/* The one thing every session starts with. It goes to the Beérkező —
          that is where uploading happens — and asks it to open the file
          dialog, so the button does something even when you are already
          there. */}
      <Link
        href="/inbox"
        onClick={() => requestUpload()}
        className="btn btn-secondary mx-3 mt-4 justify-start rounded-full py-2"
      >
        <IconUpload className="h-4 w-4 text-blue-600" />
        Irat feltöltése
      </Link>

      <nav className="mt-2 flex-1 overflow-y-auto">
        {SECTIONS.map((section) => (
          <div key={section.title}>
            <div className="nav-section">{section.title}</div>
            {section.items.map((item) => {
              const on = isActive(pathname, item);
              const count = item.href === "/inbox" ? inboxCount : 0;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={on ? "page" : undefined}
                  className={`nav-item ${on ? "nav-item-on" : ""}`}
                >
                  <item.Icon className={`h-4 w-4 ${on ? "" : "text-slate-400"}`} />
                  <span className="truncate">{item.label}</span>
                  {count > 0 && (
                    <span className="ml-auto rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">
                      {count}
                    </span>
                  )}
                </Link>
              );
            })}
          </div>
        ))}
      </nav>
    </aside>
  );
}

// Below lg the sidebar would eat the table width, so the same entries become a
// scrollable strip under the header.
export function MobileNav({ inboxCount }: { inboxCount: number }) {
  const pathname = usePathname();
  const items = SECTIONS.flatMap((s) => s.items);

  return (
    <nav className="flex gap-1 overflow-x-auto border-t border-slate-200 bg-white px-3 py-2 lg:hidden">
      {items.map((item) => {
        const on = isActive(pathname, item);
        const count = item.href === "/inbox" ? inboxCount : 0;
        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={on ? "page" : undefined}
            className={`nav-item shrink-0 py-1.5 ${on ? "nav-item-on" : ""}`}
          >
            <item.Icon className={`h-4 w-4 ${on ? "" : "text-slate-400"}`} />
            {item.label}
            {count > 0 && (
              <span className="rounded-full bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-800">
                {count}
              </span>
            )}
          </Link>
        );
      })}
    </nav>
  );
}

export function MobileLogo() {
  return (
    <div className="lg:hidden">
      <Logo />
    </div>
  );
}
