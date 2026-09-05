<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Bejelentkezes;
use App\Livewire\Auth\Regisztracio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A validációs üzenetek magyarul jelennek meg — és egyáltalán, mondatként.
 *
 * Ez a teszt egy éles hibából született. Az `APP_LOCALE=hu`, a
 * `APP_FALLBACK_LOCALE` szintén `hu`, `lang/hu/` viszont **nem létezett**, így
 * a Laravel egyetlen üzenetkulcsot sem tudott feloldani, és a nyers kulcsot
 * írta ki: az üres névvel elküldött regisztráció alatt szó szerint az állt,
 * hogy `validation.required`.
 *
 * Semmi nem jelezte. A tesztek `assertHasErrors`-t néztek, az pedig a kulcsra
 * igaz — arra nem, hogy a felhasználó mit **olvas**. Ezért itt a szövegre
 * állítunk.
 */
final class UzenetekMagyarulTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $mezok */
    private function regisztracioHibai(array $mezok): array
    {
        config(['szamlafolyo.regisztracio_nyitva' => true]);

        $komponens = Livewire::test(Regisztracio::class);

        foreach ($mezok as $mezo => $ertek) {
            $komponens->set($mezo, $ertek);
        }

        return $komponens->call('regisztracio')->errors()->all();
    }

    public function test_egyetlen_uzenet_sem_marad_nyers_kulcs(): void
    {
        $hibak = $this->regisztracioHibai([
            'nev' => '',
            'email' => 'ez-nem-email',
            'jelszo' => 'rövid',
            'jelszo_megerosites' => 'másik',
            'feltetelek' => false,
        ]);

        $this->assertNotEmpty($hibak);

        foreach ($hibak as $uzenet) {
            $this->assertStringNotContainsString(
                'validation.',
                $uzenet,
                "Feloldatlan üzenetkulcs jutott a képernyőre: {$uzenet}",
            );
        }
    }

    /**
     * A mondatokban szándékosan nincs `:attribute`: magyarul a névelő és a
     * ragozás a mezőnévtől függ, a sablon pedig egyikről sem tud. A mezőt a
     * fölötte álló címke nevezi meg — minden hiba a saját mezője alatt áll.
     */
    public function test_a_kotelezo_mezo_magyarul_szol(): void
    {
        $hibak = $this->regisztracioHibai(['nev' => '', 'feltetelek' => true]);

        $this->assertContains('Ezt a mezőt kötelező kitölteni.', $hibak);
    }

    public function test_az_email_es_a_jelszo_uzenete_is_magyar(): void
    {
        $hibak = $this->regisztracioHibai([
            'nev' => 'Teszt Elek',
            'email' => 'ez-nem-email',
            'jelszo' => 'rövid',
            'jelszo_megerosites' => 'rövid',
            'feltetelek' => true,
        ]);

        $this->assertContains('Ez nem érvényes e-mail cím.', $hibak);
        $this->assertContains('Legalább 8 karakter legyen.', $hibak);
    }

    public function test_a_foglalt_email_uzenete_is_magyar(): void
    {
        User::factory()->create(['email' => 'foglalt@example.com']);

        $hibak = $this->regisztracioHibai([
            'nev' => 'Teszt Elek',
            'email' => 'foglalt@example.com',
            'jelszo' => 'Nagyon-Hosszu-Jelszo-1',
            'jelszo_megerosites' => 'Nagyon-Hosszu-Jelszo-1',
            'feltetelek' => true,
        ]);

        $this->assertContains('Ez már foglalt.', $hibak);
    }

    /** A bejelentkezés is ugyanazon a szabálykészleten megy. */
    public function test_a_bejelentkezes_uzenetei_is_magyarok(): void
    {
        $hibak = Livewire::test(Bejelentkezes::class)
            ->set('email', '')
            ->set('jelszo', '')
            ->call('bejelentkezes')
            ->errors()
            ->all();

        $this->assertNotEmpty($hibak);

        foreach ($hibak as $uzenet) {
            $this->assertStringNotContainsString('validation.', $uzenet);
        }
    }
}
