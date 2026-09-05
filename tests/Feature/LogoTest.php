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
 * A logó mind a három elrendezésben.
 *
 * Három `<head>` és három elrendezés van (nyilvános oldal, belépés,
 * alkalmazás), és a logó mindháromban szerepel. Pontosan ez az a fajta dolog,
 * amit egy átszínezés vagy egy új képernyő félig hagy: kettő helyen az új jel,
 * a harmadikon a régi szöveg. Ezért nem szemre nézzük.
 */
final class LogoTest extends TestCase
{
    use RefreshDatabase;

    /** A jel négyzete: a designcsomag terrakottája, `design/logo/`. */
    private const JEL = 'fill="#be6846"';

    /** A dokumentum levágott lapsarka — a jel felismerhető része. */
    private const LAP = 'M14 11 H34 L42 19 V45 H14 Z';

    /** @return array<string, array{0: string}> */
    public static function kepernyok(): array
    {
        return [
            'nyitólap' => ['/'],
            'bejelentkezés' => ['/bejelentkezes'],
        ];
    }

    #[DataProvider('kepernyok')]
    public function test_a_vendegkepernyokon_ott_a_jel_es_a_ketszinu_szo(string $utvonal): void
    {
        $valasz = $this->get($utvonal)->assertOk();

        $valasz->assertSee(self::JEL, false);
        $valasz->assertSee(self::LAP, false);
        $valasz->assertSee('Számla<span class="logo-folyo">Folyó</span>', false);
    }

    public function test_az_alkalmazasban_is_ott_van(): void
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        $this->actingAs($user)->get('/beerkezo')
            ->assertOk()
            ->assertSee(self::JEL, false)
            ->assertSee('Számla<span class="logo-folyo">Folyó</span>', false);
    }

    /**
     * A szóvédjegy betűje nem a felületé: ha valaki kiveszi az Archivót a
     * betöltésből, a logó csendben átvált DM Sansra, és senki nem veszi észre.
     */
    public function test_a_szovedjegy_betuje_be_van_toltve(): void
    {
        // A betű helyben van (lásd `BetukHelybenTest`), ezért a stíluslapban
        // kell megjelennie, nem az oldal fejlécében egy külső hivatkozásként.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

        $this->assertMatchesRegularExpression('/font-family:\s*[\'"]?Archivo/', $css);
        $this->assertMatchesRegularExpression('/font-weight:\s*800/', $css);
    }

    /**
     * A fül ikonja a három állomány mindegyikével, és a `?v` léptetve — a
     * favicont gyorsítótárazza a böngésző a legmakacsabbul.
     */
    public function test_a_fulikon_harom_allomanya_ki_van_kotve(): void
    {
        $valasz = $this->get('/')->assertOk();

        foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'] as $fajl) {
            $valasz->assertSee($fajl.'?v=2', false);
        }
    }
}
