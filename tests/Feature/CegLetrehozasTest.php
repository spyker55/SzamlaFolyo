<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\CegLetrehozas;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A cégnyitás adószám-kapuja.
 *
 * Az adószám azért kötelező, mert a SzámlaFolyót kizárólag vállalkozások
 * vehetik igénybe — a fogyasztóvédelmi jog viszont kógens: hiába mondja ki
 * ezt az ÁSZF, ha a rendszer beenged egy magánszemélyt, rá attól még a
 * fogyasztói szabályok érvényesek. A gyakorlati szűrő az adószám.
 *
 * Ezért itt szigorúbb a mérce, mint a bizonylatokon: érvényes **magyar**
 * adószám kell. Az `Adoszam` osztály alapból megengedő — az a szabály a
 * *partner* adószámára szól, ez viszont a sajátunké.
 */
final class CegLetrehozasTest extends TestCase
{
    use RefreshDatabase;

    public function test_ervenyes_adoszammal_letrejon_a_ceg(): void
    {
        $user = User::factory()->create();

        $this->urlap($user)
            ->set('nev', 'Példa Kereskedelmi Kft.')
            ->set('adoszam', '10773381244')
            ->call('letrehoz')
            ->assertHasNoErrors();

        $ceg = Company::query()->sole();

        $this->assertSame('Példa Kereskedelmi Kft.', $ceg->name);
        // Tagoltan tároljuk akkor is, ha egybe írták be.
        $this->assertSame('10773381-2-44', $ceg->tax_number);
        $this->assertSame(Szerep::Tulajdonos->value, $ceg->users()->sole()->pivot->role);
    }

    /**
     * A nyolcjegyű törzsszám is átmegy: az `Adoszam::ervenyes()` elfogadja, és
     * a kapu szempontjából ugyanúgy bizonyíték — adóalanyé, nem magánszemélyé.
     */
    public function test_a_torzsszam_onmagaban_is_eleg(): void
    {
        $user = User::factory()->create();

        $this->urlap($user)
            ->set('nev', 'Példa Bt.')
            ->set('adoszam', '10773381')
            ->call('letrehoz')
            ->assertHasNoErrors();

        $this->assertSame('10773381', Company::query()->sole()->tax_number);
    }

    public function test_adoszam_nelkul_nincs_ceg(): void
    {
        $user = User::factory()->create();

        $this->urlap($user)
            ->set('nev', 'Példa Kft.')
            ->set('adoszam', '')
            ->call('letrehoz')
            ->assertHasErrors(['adoszam' => 'required']);

        $this->assertSame(0, Company::query()->count());
    }

    /**
     * Két külön baj, két külön mondat. Aki egy számjegyet gépelt el, annak az
     * ellenőrző számjegyről kell hallania; aki nem is adószámot írt be, annak
     * arról, hogy mit várunk. Ha a két üzenet összecsúszik, az elgépelő azt
     * hiszi, rosszul értette a mezőt.
     */
    #[DataProvider('rosszAdoszamok')]
    public function test_a_rossz_adoszam_megallit(string $adoszam, string $uzenetToredek): void
    {
        $user = User::factory()->create();

        $this->urlap($user)
            ->set('nev', 'Példa Kft.')
            ->set('adoszam', $adoszam)
            ->call('letrehoz')
            ->assertSee($uzenetToredek);

        $this->assertSame(0, Company::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function rosszAdoszamok(): array
    {
        return [
            // Az utolsó törzsszám-jegy elrontva: 10773381 → 10773382.
            'bukott ellenőrző számjegy' => ['10773382-2-44', 'ellenőrző számjegye'],
            'nem adószám' => ['Kft.', 'Magyar adószámot kérünk'],
            'kevés számjegy' => ['1234', 'Magyar adószámot kérünk'],
            // Szándékos szigorítás: magyar számlafeldolgozás, magyar adószám.
            'külföldi közösségi adószám' => ['DE123456789', 'Magyar adószámot kérünk'],
        ];
    }

    private function urlap(User $user): Testable
    {
        return Livewire::actingAs($user)->test(CegLetrehozas::class);
    }
}
