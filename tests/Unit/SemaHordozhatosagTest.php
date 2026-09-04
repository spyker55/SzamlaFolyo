<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extraction\Sema;
use PHPUnit\Framework\TestCase;

/**
 * Az eszközséma hordozhatósága szolgáltatók között.
 *
 * A Gemini függvényhívása nem fogadja el az unió-típust (`['string','null']`)
 * és a null-t tartalmazó enumot — az ilyen séma nála nem rosszabb válasz,
 * hanem elutasított kérés. Mivel a séma egyetlen helyen áll, egy visszacsúszás
 * itt észrevétlen maradna egészen az első Gemini-hívásig.
 *
 * Ezért a séma teljes fáját végigjárjuk, nem csak a felső szintjét.
 */
final class SemaHordozhatosagTest extends TestCase
{
    public function test_egyetlen_mezo_sem_hasznal_unio_tipust(): void
    {
        foreach ($this->csomopontok(Sema::toolSema()) as $ut => $csomopont) {
            if (! array_key_exists('type', $csomopont)) {
                continue;
            }

            $this->assertIsString(
                $csomopont['type'],
                "A(z) {$ut} típusa tömb — a Gemini ezt elutasítja.",
            );
        }
    }

    public function test_egyetlen_enum_sem_tartalmaz_nullt(): void
    {
        foreach ($this->csomopontok(Sema::toolSema()) as $ut => $csomopont) {
            if (! isset($csomopont['enum'])) {
                continue;
            }

            $this->assertNotContains(
                null,
                $csomopont['enum'],
                "A(z) {$ut} enumjában null szerepel — a Gemini ezt elutasítja.",
            );
        }
    }

    /**
     * A `required` lista csak létező mezőre hivatkozhat. Enélkül egy átnevezés
     * után a modell egy nem létező mezőt lenne köteles kitölteni.
     */
    public function test_a_required_listak_letezo_mezokre_mutatnak(): void
    {
        foreach ($this->csomopontok(Sema::toolSema()) as $ut => $csomopont) {
            if (! isset($csomopont['required'])) {
                continue;
            }

            foreach ($csomopont['required'] as $mezo) {
                $this->assertArrayHasKey(
                    $mezo,
                    $csomopont['properties'] ?? [],
                    "A(z) {$ut} kötelezőnek jelöli a(z) {$mezo} mezőt, de az nincs a sémában.",
                );
            }
        }
    }

    /**
     * A bontássor kötelező mezői pontosan azok, amiket a tisztítás is megkövetel.
     * Ha a kettő szétcsúszik, vagy a modellt kérjük olyanra, amit eldobunk, vagy
     * eldobunk olyat, amit sosem kértünk.
     */
    public function test_a_bontas_kotelezo_mezoi_egyeznek_a_tisztitassal(): void
    {
        $sor = Sema::toolSema()['properties']['afa_bontas']['items'];

        $this->assertSame(['kulcs', 'netto'], $sor['required']);

        // Amit a séma nem követel meg, azt a tisztítás is elviseli.
        $bontas = Sema::tisztitBontas([['kulcs' => 27, 'netto' => 1000]]);

        $this->assertNotNull($bontas);
        $this->assertNull($bontas[0]['kategoria']);
        $this->assertNull($bontas[0]['afa']);
    }

    /**
     * A magabiztossági objektum nem lehet szabad kulcsú.
     *
     * Mérve: szabad kulcsúként (csak `additionalProperties`, `properties`
     * nélkül) mind a három Gemini modell **némán üresen hagyta**, miközben a
     * Claude ugyanazon a sémán kitöltötte. Az üres magabiztosság nem hiba,
     * hanem ennél rosszabb: minden mező a 0,5-ös alapértelmezésre esik, és az
     * ellenőrző képernyő színkódolása pont ott veszíti el az információt,
     * amiért van.
     */
    public function test_a_magabiztossag_mezoi_fel_vannak_sorolva(): void
    {
        $konfidencia = Sema::toolSema()['properties']['confidence'];

        $this->assertFalse(
            $konfidencia['additionalProperties'],
            'A szabad kulcsú magabiztosság-objektumot a Gemini üresen hagyja.',
        );

        // Pontosan azok a mezők, amikre a tisztítás is figyel — se több, se kevesebb.
        $this->assertSame(
            [...Sema::MEZOK, 'afa_bontas'],
            array_keys($konfidencia['properties']),
        );
    }

    /**
     * Ezen áll az egész változás: ha a séma nem enged nullt, a modell a nem
     * látott mezőt **kihagyja**. A tisztításnak ugyanoda kell jutnia, mintha
     * nullt kapott volna — különben a hiányzó mező hibává válna.
     */
    public function test_a_kihagyott_mezo_ugyanaz_mint_a_null(): void
    {
        $kihagyva = Sema::tisztit([
            'doc_type' => 'szamla',
            'tobb_irat_gyanu' => false,
            'confidence' => [],
        ]);

        $nullal = Sema::tisztit([
            'doc_type' => 'szamla',
            'supplier_name' => null,
            'net_amount' => null,
            'fizetendo' => null,
            'afa_bontas' => null,
            'tobb_irat_gyanu' => false,
            'confidence' => [],
        ]);

        $this->assertSame($nullal, $kihagyva);

        // És minden mező tényleg ott van a kulcsok között, üresen.
        foreach (Sema::MEZOK as $mezo) {
            $this->assertArrayHasKey($mezo, $kihagyva['mezok']);
        }

        $this->assertNull($kihagyva['mezok']['supplier_name']);
        $this->assertNull($kihagyva['bontas']);
    }

    /**
     * A séma minden csomópontja, olvasható útvonallal.
     *
     * @return iterable<string, array<string, mixed>>
     */
    private function csomopontok(array $csomopont, string $ut = 'gyökér'): iterable
    {
        yield $ut => $csomopont;

        foreach (($csomopont['properties'] ?? []) as $nev => $gyerek) {
            if (is_array($gyerek)) {
                yield from $this->csomopontok($gyerek, $ut.'.'.$nev);
            }
        }

        if (isset($csomopont['items']) && is_array($csomopont['items'])) {
            yield from $this->csomopontok($csomopont['items'], $ut.'[]');
        }
    }
}
