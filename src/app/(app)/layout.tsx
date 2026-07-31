import Link from "next/link";
import { requireMembership } from "@/lib/tenant";
import { signOut } from "@/lib/auth/actions";

export default async function AppLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const membership = await requireMembership();

  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-gray-200 bg-white">
        <div className="mx-auto flex h-14 max-w-screen-2xl items-center gap-6 px-4">
          <Link href="/inbox" className="text-lg font-semibold text-blue-700">
            Számlafolyó
          </Link>
          <nav className="flex gap-4 text-sm">
            <Link href="/inbox" className="text-gray-700 hover:text-blue-700">
              Beérkező
            </Link>
            <Link
              href="/iktatokonyv"
              className="text-gray-700 hover:text-blue-700"
            >
              Iktatókönyv
            </Link>
            <Link href="/fizetesek" className="text-gray-700 hover:text-blue-700">
              Fizetések
            </Link>
          </nav>
          <div className="ml-auto flex items-center gap-4 text-sm text-gray-500">
            <span>{membership.companyName}</span>
            <form action={signOut}>
              <button type="submit" className="hover:text-blue-700">
                Kijelentkezés
              </button>
            </form>
          </div>
        </div>
      </header>
      <main className="mx-auto w-full max-w-screen-2xl flex-1 p-4">
        {children}
      </main>
    </div>
  );
}
