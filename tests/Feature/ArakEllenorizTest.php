<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Billing\Ar;
use App\Services\Billing\ArKatalogus;
use Tests\TestCase;

/**
 * A kiírt ár és a ténylegesen terhelt ár összevetése.
 *
 * Ez a legcsendesebb hibafajta az egész árazásban: a felületen és a nyitólapon
 * a `config/szamlafolyo.php` számai állnak, a pénzt viszont a Stripe árai
 * mozgatják, és a kettő között semmi nem garantálja az egyezést. Ha
 * elcsúsznak, minden teszt zöld marad, minden képernyő helyesnek látszik, és
 * az eltérésről a vevő a számláján értesül.
 */
final class ArakEllenorizTest extends TestCase
{
    /**
     * A Stripe helyett egy tömb. A parancsot azért lehet így tesztelni, mert
     * az `ArKatalogus` felületet ismeri, nem a `StripeSzolgaltatas`-t.
     *
     * @param  array<string, Ar|null>  $arak
     */
    private function katalogus(array $arak): void
    {
        $this->instance(ArKatalogus::class, new class($arak) implements ArKatalogus
        {
            /** @param array<string, Ar|null> $arak */
            public function __construct(private array $arak) {}

            public function ar(string $priceId): ?Ar
            {
                return $this->arak[$priceId] ?? null;
            }
        });
    }

    /** Egyetlen csomag, hogy a kimenet átlátható maradjon. */
    private function egyCsomag(int $arHavi = 1990, int $extraFt = 49): void
    {
        config(['szamlafolyo.plans' => [
            'kicsi' => [
                'nev' => 'Start',
                'documents' => 50,
                'users' => 2,
                'ar_havi' => $arHavi,
                'extra_ft' => $extraFt,
                'price_id' => 'price_havi',
                'price_id_extra' => 'price_extra',
            ],
        ]]);
    }

    public function test_egyezo_arak_eseten_sikerul(): void
    {
        $this->egyCsomag();
        $this->katalogus([
            'price_havi' => new Ar(egysegar: 199000, penznem: 'huf', ismetlodo: true, aktiv: true),
            'price_extra' => new Ar(egysegar: 4900, penznem: 'huf', ismetlodo: false, aktiv: true),
        ]);

        $this->artisan('arak:ellenoriz')
            ->expectsOutputToContain('Minden ár egyezik')
            ->assertSuccessful();
    }

    /** A legfontosabb eset: a felület mást ígér, mint amit terhelünk. */
    public function test_az_eltero_osszeg_kibukik(): void
    {
        $this->egyCsomag(arHavi: 1990);
        $this->katalogus([
            'price_havi' => new Ar(egysegar: 249000, penznem: 'huf', ismetlodo: true, aktiv: true),
            'price_extra' => new Ar(egysegar: 4900, penznem: 'huf', ismetlodo: false, aktiv: true),
        ]);

        $this->artisan('arak:ellenoriz')
            ->expectsOutputToContain('2 490')
            ->assertFailed();
    }

    /**
     * A darabárnak egyszeri árnak kell lennie: a `tulhasznalat:elszamol`
     * darabszámmal tesz fel belőle tételt a következő számlára. Ismétlődő
     * árként ugyanez külön előfizetést indítana.
     */
    public function test_az_ismetlodo_darabar_kibukik(): void
    {
        $this->egyCsomag();
        $this->katalogus([
            'price_havi' => new Ar(egysegar: 199000, penznem: 'huf', ismetlodo: true, aktiv: true),
            'price_extra' => new Ar(egysegar: 4900, penznem: 'huf', ismetlodo: true, aktiv: true),
        ]);

        $this->artisan('arak:ellenoriz')
            ->expectsOutputToContain('egyszerinek')
            ->assertFailed();
    }

    public function test_a_hianyzo_arazonosito_kibukik(): void
    {
        $this->egyCsomag();
        config(['szamlafolyo.plans.kicsi.price_id_extra' => null]);
        $this->katalogus([
            'price_havi' => new Ar(egysegar: 199000, penznem: 'huf', ismetlodo: true, aktiv: true),
        ]);

        $this->artisan('arak:ellenoriz')
            ->expectsOutputToContain('HIÁNYZIK')
            ->assertFailed();
    }

    /** A Stripe nem ismeri az .env-be írt azonosítót — elgépelés vagy törlés. */
    public function test_az_ismeretlen_arazonosito_kibukik(): void
    {
        $this->egyCsomag();
        $this->katalogus([]);

        $this->artisan('arak:ellenoriz')
            ->expectsOutputToContain('NINCS MEG')
            ->assertFailed();
    }

    public function test_az_archivalt_ar_kibukik(): void
    {
        $this->egyCsomag();
        $this->katalogus([
            'price_havi' => new Ar(egysegar: 199000, penznem: 'huf', ismetlodo: true, aktiv: false),
            'price_extra' => new Ar(egysegar: 4900, penznem: 'huf', ismetlodo: false, aktiv: true),
        ]);

        $this->artisan('arak:ellenoriz')
            ->expectsOutputToContain('archivált')
            ->assertFailed();
    }
}
