<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\DokumentumAllapot;
use App\Models\Company;
use App\Models\Document;
use App\Services\Billing\Kvota;
use App\Support\Berlo;
use Illuminate\Support\Facades\DB;

/**
 * A feldolgozási sor worker nélkül.
 *
 * Osztott tárhelyen nincs hosszú életű folyamat, ezért a sort két dolog hajtja:
 * a böngésző (amíg a felhasználó nézi a Beérkezőt) és a cron (mindenki másért).
 * Mindkettő ugyanezt a claimet használja, ezért nem tudják ugyanazt a
 * dokumentumot kétszer kiolvasni.
 *
 * A claim egyetlen feltételes UPDATE: aki elsőnek írja át az állapotot, azé a
 * munka. Nem kell hozzá sem sorzár, sem külön sortábla.
 */
final class Sorkezelo
{
    public function __construct(
        private readonly Kiolvaso $kiolvaso,
        private readonly Berlo $berlo,
    ) {}

    /**
     * Egy dokumentum feldolgozása az adott cégnél, ha van rá keret.
     *
     * @return bool volt-e mit csinálni
     */
    public function egyet(Company $ceg): bool
    {
        $this->elakadtakFelszabaditasa();

        if (! (new Kvota($ceg))->vanMegKeret()) {
            return false;
        }

        $dokumentum = $this->kovetkezo($ceg);

        if ($dokumentum === null) {
            return false;
        }

        $this->berlo->nevében($ceg, function () use ($dokumentum): void {
            $this->kiolvaso->futtat($dokumentum);
        });

        return true;
    }

    /** @return int hány dokumentumot dolgozott fel */
    public function tobbet(Company $ceg, int $darab): int
    {
        $feldolgozott = 0;

        for ($i = 0; $i < $darab; $i++) {
            if (! $this->egyet($ceg)) {
                break;
            }

            $feldolgozott++;
        }

        return $feldolgozott;
    }

    /** Van-e még a cégnél feldolgozásra váró irat. */
    public function varakozikMeg(Company $ceg): bool
    {
        return Document::query()
            ->withoutGlobalScopes()
            ->where('company_id', $ceg->id)
            ->whereIn('status', [
                DokumentumAllapot::Feltoltve->value,
                DokumentumAllapot::FeldolgozasAlatt->value,
            ])
            ->exists();
    }

    /**
     * A soron következő dokumentum kivétele. A `where status = 'feltoltve'`
     * feltétel az, ami miatt két párhuzamos hívás nem viheti el ugyanazt: a
     * második UPDATE nulla sort érint.
     */
    private function kovetkezo(Company $ceg): ?Document
    {
        $max = (int) config('szamlafolyo.extraction.max_attempts');

        return DB::transaction(function () use ($ceg, $max): ?Document {
            $jelolt = Document::query()
                ->withoutGlobalScopes()
                ->where('company_id', $ceg->id)
                ->where('status', DokumentumAllapot::Feltoltve->value)
                ->where('attempts', '<', $max)
                ->whereNotNull('storage_path')
                ->orderBy('id')
                ->first();

            if ($jelolt === null) {
                return null;
            }

            $sikerult = Document::query()
                ->withoutGlobalScopes()
                ->whereKey($jelolt->id)
                ->where('status', DokumentumAllapot::Feltoltve->value)
                ->update([
                    'status' => DokumentumAllapot::FeldolgozasAlatt->value,
                    'claimed_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            return $sikerult === 1 ? $jelolt->refresh() : null;
        });
    }

    /**
     * Ami feldolgozás közben megállt (a PHP-folyamat elszállt, a böngésző
     * bezárult), az egy idő után visszakerül a sorba. Enélkül némán ott
     * maradna örökre.
     */
    private function elakadtakFelszabaditasa(): void
    {
        $perc = (int) config('szamlafolyo.extraction.claim_timeout_minutes');

        Document::query()
            ->withoutGlobalScopes()
            ->where('status', DokumentumAllapot::FeldolgozasAlatt->value)
            ->where('claimed_at', '<', now()->subMinutes($perc))
            ->update([
                'status' => DokumentumAllapot::Feltoltve->value,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }
}
