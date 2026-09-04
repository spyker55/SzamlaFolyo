<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Az árlépcső két szabálya, amit egy „gyors áremelés" könnyen elront.
 *
 * Mindkettőt élőben rontottuk el egyszer: a Start darabára (39 Ft) egy ideig
 * *olcsóbb* volt, mint a csomag saját darabára (1 990 / 50 = 39,8 Ft), a Pro
 * pedig háromszoros árért ötszörös keretet adott, amitől a Flow-ról csak 545
 * dokumentumnál érte meg váltani. Egyik hiba sem látszik a képernyőn — csak
 * abban, hogy senki nem vált csomagot.
 */
final class ArlepcsoTest extends TestCase
{
    /** @return array<int, array{kulcs: string, nev: string, darabar: float, extra: int}> */
    private function csomagok(): array
    {
        $ki = [];

        foreach ((array) config('szamlafolyo.plans') as $kulcs => $csomag) {
            $ki[] = [
                'kulcs' => (string) $kulcs,
                'nev' => (string) $csomag['nev'],
                'darabar' => (int) $csomag['ar_havi'] / (int) $csomag['documents'],
                'extra' => (int) $csomag['extra_ft'],
            ];
        }

        return $ki;
    }

    /**
     * A keret fölötti darab mindig drágább, mint a csomagban lévő.
     *
     * Különben azt tanítjuk, hogy megéri a kis csomagban maradni és túllépni —
     * pont az ellenkezőjét annak, amiért a lépcső létezik.
     */
    public function test_az_extra_darabar_dragabb_a_csomag_sajat_darabaranal(): void
    {
        foreach ($this->csomagok() as $cs) {
            $this->assertGreaterThan(
                $cs['darabar'],
                $cs['extra'],
                sprintf(
                    'A(z) %s extra darabára (%d Ft) olcsóbb, mint a csomag saját darabára (%.2f Ft).',
                    $cs['nev'], $cs['extra'], $cs['darabar'],
                ),
            );
        }
    }

    /** Nagyobb csomag = olcsóbb darabár. Enélkül a felfelé lépésnek nincs értelme. */
    public function test_a_darabar_csomagrol_csomagra_csokken(): void
    {
        $csomagok = $this->csomagok();

        for ($i = 1; $i < count($csomagok); $i++) {
            $this->assertLessThan(
                $csomagok[$i - 1]['darabar'],
                $csomagok[$i]['darabar'],
                sprintf(
                    'A(z) %s darabára (%.2f Ft) nem olcsóbb a(z) %s csomagénál (%.2f Ft).',
                    $csomagok[$i]['nev'], $csomagok[$i]['darabar'],
                    $csomagok[$i - 1]['nev'], $csomagok[$i - 1]['darabar'],
                ),
            );
        }
    }
}
