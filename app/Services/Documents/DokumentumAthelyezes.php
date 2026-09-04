<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DokumentumAllapot;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentCorrection;
use App\Models\DocumentExtraction;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Egy irat átvitele a felhasználó másik cégéhez.
 *
 * Ez az egyetlen művelet a rendszerben, ami **átlép a bérlőhatáron**, ezért a
 * feltételei szigorúak és mind ki vannak mondva. A bérlő-elkülönítés minden
 * más ponton abszolút; itt egy ember mondja meg tudatosan, hogy az irat rossz
 * helyre került, és mindkét cégben joga van hozzányúlni.
 *
 * Amit **nem** viszünk át: az `inbound_email_id`. A levél tényleg a forráscég
 * beküldési címére érkezett, ez történelmi tény róla — a hivatkozás megmarad,
 * a célcég egyszerűen nem látja (a bérlőszűrő nem engedi).
 *
 * A keret ezzel együtt mozog: a kiolvasás sorai viszik a `credits` értéküket,
 * tehát a forráscég felhasználása csökken, a célcégé nő. Ez a helyes irány —
 * a modellhívás a célcég iratáért történt.
 */
final class DokumentumAthelyezes
{
    private const LEMEZ = 'local';

    /** Áthelyezhető állapotok: amit még nem könyveltek el a forráscégnél. */
    private const VIHETO_ALLAPOTOK = [
        DokumentumAllapot::Feltoltve,
        DokumentumAllapot::EllenorzesreVar,
        DokumentumAllapot::Hiba,
    ];

    /** @throws AthelyezesHiba */
    public function athelyez(Document $dokumentum, Company $cel, User $felhasznalo): void
    {
        $this->ellenoriz($dokumentum, $cel, $felhasznalo);

        $forrasId = (int) $dokumentum->company_id;
        $regiUtvonal = $dokumentum->storage_path;
        $ujUtvonal = $this->ujUtvonal($dokumentum, $cel);

        DB::transaction(function () use ($dokumentum, $cel, $forrasId, $regiUtvonal, $ujUtvonal): void {
            // A kapcsolódó sorok a dokumentumé, tehát velük együtt költöznek.
            // A globális bérlőszűrőt ki kell kapcsolni: a célcég sorait épp
            // most hozzuk létre, a forráscégéit pedig már nem szűrné a
            // jelenlegi bérlő, ha az a cél.
            foreach ([DocumentExtraction::class, DocumentCorrection::class] as $osztaly) {
                $osztaly::query()->withoutGlobalScopes()
                    ->where('document_id', $dokumentum->id)
                    ->update(['company_id' => $cel->id]);
            }

            $dokumentum->forceFill([
                'company_id' => $cel->id,
                'storage_path' => $ujUtvonal,
            ])->save();

            // A fájl mozgatása **a soríráson belül**: ha a átnevezés elbukik
            // (jogosultság, betelt lemez), a tranzakció visszagördül, és nem
            // marad olyan sor, ami nem létező fájlra mutat.
            if ($regiUtvonal !== null && $ujUtvonal !== null) {
                $lemez = Storage::disk(self::LEMEZ);

                if ($lemez->exists($regiUtvonal) && ! $lemez->move($regiUtvonal, $ujUtvonal)) {
                    throw new AthelyezesHiba('A fájlt nem sikerült átmozgatni a másik cég tárhelyére.');
                }
            }

            // Mindkét oldalon nyoma marad: a forráscégnél azért, mert onnan
            // eltűnt egy irat, a célcégnél azért, mert ott megjelent.
            $berlo = app(Berlo::class);
            $nev = (string) $dokumentum->original_filename;

            $berlo->nevében(Company::query()->withoutGlobalScopes()->find($forrasId), fn () => ActivityLog::rogzit(
                'dokumentum.elvitte', $dokumentum, "{$nev} → {$cel->name}",
            ));

            $berlo->nevében($cel, fn () => ActivityLog::rogzit(
                'dokumentum.erkezett', $dokumentum, $nev,
            ));
        });
    }

    /** @throws AthelyezesHiba */
    private function ellenoriz(Document $dokumentum, Company $cel, User $felhasznalo): void
    {
        if ((int) $dokumentum->company_id === (int) $cel->id) {
            throw new AthelyezesHiba('Ez az irat már ehhez a céghez tartozik.');
        }

        // Mindkét oldalon kell jog: elvinni onnan, ahol van, és letenni oda,
        // ahova megy. A célcégbeli tagság ellenőrzése nélkül ez a művelet
        // pont a bérlőhatárt bontaná le.
        $forras = Company::query()->withoutGlobalScopes()->find($dokumentum->company_id);

        if ($forras === null
            || ! ($felhasznalo->szerepe($forras)?->szerkeszthet() ?? false)
            || ! ($felhasznalo->szerepe($cel)?->szerkeszthet() ?? false)) {
            throw new AthelyezesHiba('Ehhez mindkét cégben szerkesztési jog kell.');
        }

        if (! in_array($dokumentum->status, self::VIHETO_ALLAPOTOK, true)) {
            throw new AthelyezesHiba(
                'Csak ellenőrzés előtt álló iratot lehet átvinni. Ez már jóváhagyott, exportált vagy '
                .'éppen feldolgozás alatt áll — a forráscég könyvelése nem változhat meg utólag, észrevétlenül.',
            );
        }

        // A duplikátumok ugyanarra a fájlra mutatnak, mint az eredeti: ha az
        // egyiket elvinnénk, a másik útvonala a semmibe mutatna.
        $duplikatuma = Document::query()->withoutGlobalScopes()
            ->where('duplicate_of_id', $dokumentum->id)->exists();

        if ($dokumentum->duplicate_of_id !== null || $duplikatuma) {
            throw new AthelyezesHiba('Duplikátumot és duplikált iratot nem viszünk át: közös fájlon osztoznak.');
        }

        if ($dokumentum->sha256 !== null && Document::query()->withoutGlobalScopes()
            ->where('company_id', $cel->id)
            ->where('sha256', $dokumentum->sha256)
            ->where('status', '!=', DokumentumAllapot::Duplikatum->value)
            ->exists()) {
            throw new AthelyezesHiba("Ez az irat már bent van a(z) „{$cel->name}” cégnél.");
        }
    }

    /** A fájl a cég azonosítója alatt lakik, tehát az útvonal is költözik. */
    private function ujUtvonal(Document $dokumentum, Company $cel): ?string
    {
        if ($dokumentum->storage_path === null) {
            return null;
        }

        return sprintf('iratok/%d/%d/%s', $cel->id, $dokumentum->id, basename($dokumentum->storage_path));
    }
}
