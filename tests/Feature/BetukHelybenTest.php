<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A betűk a saját kiszolgálónkról jönnek.
 *
 * A Google Fonts minden oldalletöltéskor elküldi a látogató IP-címét a
 * Google-nek — azét is, aki be sem lépett, és nem is fog. Egy magyar
 * számlafeldolgozónak ezt nincs miért vállalnia, az adatkezelési tájékoztató
 * pedig ki is mondja, hogy az oldal megnyitása önmagában nem jár
 * adattovábbítással harmadik félhez.
 *
 * Ez az állítás azt őrzi, hogy egy később bemásolt `<link>` — a betűbetöltés
 * a leggyakoribb ilyen másolás — ne csinálja ezt az ígéretet hazuggá. Mind a
 * négy elrendezésre külön fut, mert egy `<head>`-et mindig elfelejt az ember.
 */
final class BetukHelybenTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: bool}> */
    public static function elrendezesek(): array
    {
        return [
            'nyitólap' => ['/', false],
            'belépés' => ['/bejelentkezes', false],
            'jogi oldal' => ['/aszf', false],
            'alkalmazás' => ['/beerkezo', true],
        ];
    }

    #[DataProvider('elrendezesek')]
    public function test_egyik_oldal_sem_tolt_kulso_betut(string $utvonal, bool $belepve): void
    {
        $valasz = $belepve
            ? $this->actingAs($this->belepett())->get($utvonal)
            : $this->get($utvonal);

        $valasz->assertOk()
            ->assertDontSee('fonts.googleapis.com')
            ->assertDontSee('fonts.gstatic.com');
    }

    /**
     * A stíluslap tényleg hivatkozik a helyi betűre. Enélkül a fenti állítás
     * akkor is zöld maradna, ha a betűk egyszerűen eltűnnének — a hiány is
     * „nem tölt külső betűt".
     */
    public function test_a_stiluslap_helyi_betut_hivatkozik(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

        $this->assertStringContainsString('DM Sans Variable', $css);
        $this->assertStringContainsString('.woff2', $css);
        $this->assertStringNotContainsString('fonts.gstatic.com', $css);
    }

    private function belepett(): User
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        return $user;
    }
}
