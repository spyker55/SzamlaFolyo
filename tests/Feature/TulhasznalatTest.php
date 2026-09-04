<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\User;
use App\Services\Billing\SzamlazoKapu;
use App\Services\Billing\Tulhasznalat;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A keret fölötti darabok elszámolása.
 *
 * A legsúlyosabb hiba, amit itt el lehet követni, a **kétszeres terhelés**.
 * A szolgáltatás ezért sosem azt nézi, „mennyi jött be azóta", hanem a
 * ténylegesen túllépett és a már kiszámlázott kreditek különbségét — így egy
 * megszakadt vagy megismételt futás nem terhel újra.
 */
final class TulhasznalatTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{darab: int}> */
    private array $terhelesek = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'szamlafolyo.plans.kicsi.price_id' => 'price_kicsi',
            'szamlafolyo.plans.kicsi.price_id_extra' => 'price_kicsi_extra',
        ]);

        $this->terhelesek = [];

        // A Stripe helyett feljegyezzük, mit terheltünk volna. Így az
        // idempotencia állítható anélkül, hogy hálózatra mennénk.
        $this->instance(SzamlazoKapu::class, new class($this->terhelesek) implements SzamlazoKapu
        {
            /** @param  array<int, array{darab: int}>  $kifele */
            public function __construct(private array &$kifele) {}

            public function extraTetel(Company $ceg, string $email, string $priceId, int $darab): string
            {
                $this->kifele[] = ['darab' => $darab];

                return 'ii_'.count($this->kifele);
            }
        });
    }

    private function ceg(bool $engedve = true): Company
    {
        $ceg = Company::factory()->create([
            'stripe_status' => 'active',
            'stripe_price_id' => 'price_kicsi',
            'stripe_customer_id' => 'cus_teszt',
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
            'overage_enabled' => $engedve,
        ]);

        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);
        app(Berlo::class)->beallit($ceg);

        return $ceg;
    }

    private function kiolvasas(Company $ceg, int $kreditek): void
    {
        $dokumentum = Document::factory()->create(['company_id' => $ceg->id]);

        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'prompt_version' => 'teszt',
            'credits' => $kreditek,
        ]);
        $kiolvasas->company_id = $ceg->id;
        $kiolvasas->save();
    }

    public function test_a_keret_folotti_kreditek_szamlara_kerulnek(): void
    {
        $ceg = $this->ceg();
        $this->kiolvasas($ceg, 55);   // a Start keret 50

        $darab = app(Tulhasznalat::class)->cegre($ceg);

        $this->assertSame(5, $darab);
        $this->assertSame([['darab' => 5]], $this->terhelesek);
        $this->assertDatabaseHas('overage_charges', ['company_id' => $ceg->id, 'credits' => 5]);
    }

    /** A lényegi állítás: kétszeri futás nem terhel kétszer. */
    public function test_az_ismetelt_futas_nem_terhel_ujra(): void
    {
        $ceg = $this->ceg();
        $this->kiolvasas($ceg, 55);

        app(Tulhasznalat::class)->cegre($ceg);
        $masodik = app(Tulhasznalat::class)->cegre($ceg);

        $this->assertSame(0, $masodik);
        $this->assertCount(1, $this->terhelesek);
    }

    /** Ami az első futás után jött be, azt a következő futás viszi fel. */
    public function test_a_kovetkezo_futas_csak_a_kulonbozetet_viszi(): void
    {
        $ceg = $this->ceg();
        $this->kiolvasas($ceg, 55);
        app(Tulhasznalat::class)->cegre($ceg);

        $this->kiolvasas($ceg, 3);
        $ujabb = app(Tulhasznalat::class)->cegre($ceg);

        $this->assertSame(3, $ujabb);
        $this->assertSame([['darab' => 5], ['darab' => 3]], $this->terhelesek);
    }

    public function test_kikapcsolt_tulhasznalatnal_nem_terhel(): void
    {
        $ceg = $this->ceg(engedve: false);
        $this->kiolvasas($ceg, 55);

        $this->assertSame(0, app(Tulhasznalat::class)->cegre($ceg));
        $this->assertSame([], $this->terhelesek);
    }

    /**
     * Engedélyezett túlhasználat darabár nélkül: inkább dolgozzon ingyen a
     * felhasználó, mint hogy találgatott áron terheljük.
     */
    public function test_darabar_nelkul_nem_terhel(): void
    {
        config(['szamlafolyo.plans.kicsi.price_id_extra' => null]);

        $ceg = $this->ceg();
        $this->kiolvasas($ceg, 55);

        $this->assertSame(0, app(Tulhasznalat::class)->cegre($ceg));
        $this->assertSame([], $this->terhelesek);
    }

    public function test_a_kereten_belul_nincs_mit_terhelni(): void
    {
        $ceg = $this->ceg();
        $this->kiolvasas($ceg, 40);

        $this->assertSame(0, app(Tulhasznalat::class)->cegre($ceg));
        $this->assertSame([], $this->terhelesek);
    }
}
