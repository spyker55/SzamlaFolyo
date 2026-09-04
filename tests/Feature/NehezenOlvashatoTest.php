<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Szerep;
use App\Livewire\App\Ellenorzes;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Extraction\Kiolvaso;
use App\Services\Extraction\Prompt;
use App\Services\Extraction\Sema;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A bizonylat egészére szóló figyelmeztetés.
 *
 * Miért nem mezőszintű: egy valódi, kézzel írott számlán a modell helyesen
 * olvasta ki mindkét adószámot, a számlaszámot, mind a három dátumot és az
 * összegeket — a szállító nevét viszont **kitalálta**. A beírt név semmiben nem
 * hasonlított a papíron állóra, tehát nem félreolvasás volt, hanem kitöltés. A
 * számtan hibátlan maradt (nulla ÁFA-nál nincs mit elrontani), mindkét adószám
 * átment az ellenőrző számjegyen, a névre pedig nincs és nem is lehet
 * determinisztikus ellenőrzésünk.
 *
 * Ez a zászló ezért nem azt állítja, hogy tudjuk, hol a hiba. Azt mondja ki,
 * hogy ezen az iraton semmiért nem tudunk jótállni.
 */
final class NehezenOlvashatoTest extends TestCase
{
    use RefreshDatabase;

    private Company $ceg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceg = Company::factory()->create();
        $user = User::factory()->create();
        $this->ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        app(Berlo::class)->beallit($this->ceg);
        $this->actingAs($user);
    }

    /**
     * A hallgatás itt nem lehet válasz. „Kézzel írott-e ez a papír" ellenőrizhető
     * tény, nem önértékelés — ezért kérjük kötelezően, szemben a
     * magabiztossággal, ami háromszor is használhatatlannak bizonyult.
     */
    public function test_a_sema_kotelezoen_keri_a_zaszlot(): void
    {
        $sema = Sema::toolSema();

        $this->assertContains('nehezen_olvashato', $sema['required']);
        $this->assertSame('boolean', $sema['properties']['nehezen_olvashato']['type']);
    }

    public function test_a_valasz_zaszlaja_atjon_a_tisztitason(): void
    {
        $this->assertTrue(Sema::tisztit(['nehezen_olvashato' => true])['nehezen_olvashato']);

        // Ha a modell elhagyja, nem tekintjük nehezen olvashatónak — de a
        // sémában kötelező, tehát ez csak a hibás válasz elleni védelem.
        $this->assertFalse(Sema::tisztit([])['nehezen_olvashato']);
    }

    public function test_a_kepernyo_kimondja_hogy_mindent_at_kell_nezni(): void
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->ceg->id,
            'nehezen_olvashato' => true,
        ]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertSee('Kézzel írott vagy nehezen olvasható bizonylat.')
            ->assertSee('minden mezőt vess össze a papírral')
            // A neveket külön ki kell emelni: azok az egyetlen mezők, amikhez
            // semmilyen ellenőrzésünk nincs.
            ->assertSee('Különösen a neveket');
    }

    /** Rendes bizonylaton ne legyen ott — egy állandó figyelmeztetés nem figyelmeztetés. */
    public function test_a_jol_olvashato_bizonylaton_nincs_figyelmeztetes(): void
    {
        $dokumentum = Document::factory()->ellenorzesreVar()->create([
            'company_id' => $this->ceg->id,
        ]);

        Livewire::test(Ellenorzes::class, ['dokumentum' => $dokumentum])
            ->assertDontSee('nehezen olvasható bizonylat');
    }

    /**
     * A zászló eljut a modell válaszától az oszlopig — enélkül a figyelmeztetés
     * sosem jelenne meg éles iraton.
     */
    public function test_a_modell_zaszlaja_eljut_az_oszlopig(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001],
                'choices' => [[
                    'message' => [
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'record_extraction',
                                'arguments' => json_encode([
                                    'doc_type' => 'szamla',
                                    'doc_number' => 'SEASA7371803',
                                    'net_amount' => '145 000',
                                    'vat_amount' => '0',
                                    'gross_amount' => '145 000',
                                    'currency' => 'HUF',
                                    'tobb_irat_gyanu' => false,
                                    'nehezen_olvashato' => true,
                                    'confidence' => ['doc_number' => 0.9],
                                ]),
                            ],
                        ]],
                    ],
                ]],
            ]),
        ]);

        $dokumentum = Document::factory()->create([
            'company_id' => $this->ceg->id,
            'storage_path' => null,
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('iratok/proba.pdf', '%PDF-1.4 teszt');
        $dokumentum->forceFill(['storage_path' => 'iratok/proba.pdf'])->save();

        app(Kiolvaso::class)->futtat($dokumentum);

        $this->assertTrue($dokumentum->fresh()->nehezen_olvashato);
    }

    /**
     * A prompt tiltja a nevek kitalálását. Ez a kitalált névre adott közvetlen
     * válasz: a hiányzó név feltűnik az embernek, a kitalált nem.
     */
    public function test_a_prompt_tiltja_a_nev_kitalalasat(): void
    {
        $prompt = Prompt::rendszer();

        $this->assertStringContainsString('nevekre', $prompt);
        $this->assertStringContainsString('hagyd ki a mezőt', $prompt);
        $this->assertStringContainsString('nehezen_olvashato', $prompt);
    }
}
