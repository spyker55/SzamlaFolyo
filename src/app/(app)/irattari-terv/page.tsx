import Link from "next/link";
import { requireMembership } from "@/lib/tenant";
import { createSupabaseServerClient } from "@/lib/supabase/server";
import { PageHeader, EmptyState } from "@/components/ui/page";
import { IrattariTervClient, type TetelRow } from "@/components/irattar/IrattariTervClient";
import {
  budapestEv,
  megorzes,
  megorzesSzoveg,
  MEGORZES_LABEL,
  megorzesStilus,
} from "@/lib/irattar/terv";
import { UGY_STATUS_LABEL, type UgyStatus } from "@/lib/ugy/status";
import { IconBook } from "@/components/ui/icons";

type TetelDb = {
  id: string;
  tetelszam: string;
  nev: string;
  orzesi_ido_ev: number | null;
  jogszabaly: string | null;
  megjegyzes: string | null;
  sorrend: number;
  deleted_at: string | null;
};

type UgyDb = {
  id: string;
  foszam: number;
  ev: number;
  targy: string | null;
  status: string;
  closed_at: string | null;
  irattari_tetel_id: string | null;
};

export default async function IrattariTervPage() {
  const { role } = await requireMembership();
  const supabase = await createSupabaseServerClient();

  const [{ data: tetelData }, { data: ugyData }] = await Promise.all([
    supabase
      .from("irattari_tetel")
      .select("id, tetelszam, nev, orzesi_ido_ev, jogszabaly, megjegyzes, sorrend, deleted_at")
      .order("sorrend")
      .order("tetelszam")
      .limit(500),
    supabase
      .from("ugy")
      .select("id, foszam, ev, targy, status, closed_at, irattari_tetel_id")
      .limit(2000),
  ]);

  const tetelek = (tetelData ?? []) as unknown as TetelDb[];
  const ugyek = (ugyData ?? []) as unknown as UgyDb[];

  const orzesById = new Map(tetelek.map((t) => [t.id, t.orzesi_ido_ev]));

  const countByTetel = new Map<string, number>();
  for (const u of ugyek) {
    if (!u.irattari_tetel_id) continue;
    countByTetel.set(u.irattari_tetel_id, (countByTetel.get(u.irattari_tetel_id) ?? 0) + 1);
  }

  const rows: TetelRow[] = tetelek.map((t) => ({
    id: t.id,
    tetelszam: t.tetelszam,
    nev: t.nev,
    orzesiIdoEv: t.orzesi_ido_ev,
    jogszabaly: t.jogszabaly,
    megjegyzes: t.megjegyzes,
    sorrend: t.sorrend,
    aktiv: t.deleted_at === null,
    ugyCount: countByTetel.get(t.id) ?? 0,
  }));

  const mostEv = Number(
    new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Budapest", year: "numeric" }).format(
      new Date()
    )
  );

  const allapotOf = (u: UgyDb) =>
    megorzes(
      budapestEv(u.closed_at),
      u.irattari_tetel_id ? orzesById.get(u.irattari_tetel_id) ?? null : null,
      mostEv,
      u.irattari_tetel_id !== null
    );

  // Only ügyek that are actually finished. A running case's retention has not
  // started, and an unclassified running case is not a gap — it is a case
  // somebody is still working on.
  const zart = ugyek.filter((u) => u.status === "lezart" || u.status === "irattarazott");

  const selejtezheto = zart
    .map((u) => ({ ugy: u, m: allapotOf(u) }))
    .filter((x) => x.m.allapot === "selejtezheto")
    .sort((a, b) => (a.m.selejtezhetoEv ?? 0) - (b.m.selejtezhetoEv ?? 0));

  const besorolatlan = zart.filter((u) => u.irattari_tetel_id === null);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Irattári terv"
        description="Melyik ügytípust meddig kell megőrizni, és mikortól nem. A tervet a cég maga alakítja; az alábbi kiindulás a törvényi minimumokat tartalmazza, a hivatkozott jogszabállyal együtt."
      />

      <p className="alert alert-info text-sm">
        A rendszer <strong>soha nem töröl</strong>. A selejtezhető ügyeket kilistázza, a
        döntést és a megsemmisítést emberre hagyja — az iktatott irat fizikai törlése az
        adatbázisban is tiltott marad.
      </p>

      {besorolatlan.length > 0 && (
        <p className="alert alert-warn text-sm">
          {besorolatlan.length} lezárt vagy irattárazott ügynek nincs irattári tétele, ezért
          a megőrzési idejük nem számolható. Amíg nincsenek besorolva, megmaradnak — a
          besorolás az ügy adatlapján állítható.
        </p>
      )}

      <div className="card">
        <div className="card-head">
          <h2 className="card-title">Selejtezhető ügyek ({selejtezheto.length})</h2>
        </div>
        <div className="px-4 pb-2">
          {selejtezheto.length === 0 ? (
            <EmptyState
              icon={<IconBook className="h-8 w-8" />}
              hint="Ez a lista akkor telik meg, ha egy lezárt ügy megőrzési ideje lejár. A megőrzési idő az ügy lezárásának évétől számít."
            >
              Jelenleg egyetlen ügy megőrzési ideje sem járt le.
            </EmptyState>
          ) : (
            <div className="table-scroll">
              <table className="tbl">
                <thead className="thead">
                  <tr>
                    <th className="th">Iktatószám</th>
                    <th className="th">Tárgy</th>
                    <th className="th">Állapot</th>
                    <th className="th">Lezárva</th>
                    <th className="th">Megőrzés</th>
                  </tr>
                </thead>
                <tbody>
                  {selejtezheto.map(({ ugy, m }) => (
                    <tr key={ugy.id} className="trow">
                      <td className="td whitespace-nowrap font-medium tabular-nums">
                        <Link href={`/ugyek/${ugy.id}`} className="link">
                          IKT/{ugy.foszam}/{ugy.ev}
                        </Link>
                      </td>
                      <td className="td max-w-md truncate">{ugy.targy ?? "—"}</td>
                      <td className="td whitespace-nowrap">
                        {UGY_STATUS_LABEL[ugy.status as UgyStatus] ?? ugy.status}
                      </td>
                      <td className="td whitespace-nowrap tabular-nums">
                        {ugy.closed_at?.slice(0, 10) ?? "—"}
                      </td>
                      <td className="td whitespace-nowrap">
                        <span className={`badge ${megorzesStilus(m.allapot)}`}>
                          {MEGORZES_LABEL[m.allapot]}
                        </span>
                        <span className="mt-0.5 block text-xs text-slate-500">
                          {megorzesSzoveg(m)}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      <IrattariTervClient
        tetelek={rows}
        canEdit={role === "owner" || role === "admin"}
      />
    </div>
  );
}
