<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DokumentumAllapot;
use App\Enums\Szerep;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Berlo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A böngészőnek szóló védelmi fejlécek.
 *
 * Ezek olyan hiányok voltak, amiket semmi nem jelzett: az oldal nélkülük is
 * tökéletesen működött, csak épp keretezhető volt egy idegen lapon, és az
 * iratok sorszámot hordozó URL-jei kimentek a hivatkozó fejlécben.
 */
final class BiztonsagiFejlecekTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function fejlecek(): array
    {
        return [
            'keretezés' => ['X-Frame-Options', 'SAMEORIGIN'],
            'típustalálgatás' => ['X-Content-Type-Options', 'nosniff'],
            'hivatkozó' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'keretező oldalak' => ['Content-Security-Policy', "frame-ancestors 'self'"],
        ];
    }

    #[DataProvider('fejlecek')]
    public function test_a_nyilvanos_oldal_is_megkapja(string $fejlec, string $ertek): void
    {
        $this->get('/')->assertOk()->assertHeader($fejlec, $ertek);
    }

    #[DataProvider('fejlecek')]
    public function test_a_belepett_kepernyo_is_megkapja(string $fejlec, string $ertek): void
    {
        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);

        $this->actingAs($user)->get('/beerkezo')->assertOk()->assertHeader($fejlec, $ertek);
    }

    /**
     * A `DENY` itt hibás lenne: az ellenőrző képernyő a bizonylatot saját
     * eredetű iframe-ben mutatja. A fájl kiszolgálása erre a legérzékenyebb
     * pont, ezért külön is megnézzük.
     */
    public function test_a_bizonylat_sajat_iframe_ben_megmarad(): void
    {
        Storage::fake('local');

        $ceg = Company::factory()->create();
        $user = User::factory()->create();
        $ceg->users()->attach($user->id, ['role' => Szerep::Tulajdonos->value, 'accepted_at' => now()]);
        app(Berlo::class)->beallit($ceg);

        $irat = Document::factory()->create([
            'company_id' => $ceg->id,
            'status' => DokumentumAllapot::EllenorzesreVar->value,
            'mime_type' => 'application/pdf',
            'storage_path' => 'iratok/proba.pdf',
            'file_deleted_at' => null,
        ]);
        Storage::disk('local')->put('iratok/proba.pdf', '%PDF-1.4 teszt');

        $this->actingAs($user)
            ->get(route('dokumentum.fajl', $irat))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * A HSTS-t a böngésző hónapokra megjegyzi: egy tanúsítvány nélküli
     * fejlesztői gépet ezzel ki lehetne zárni magunkból.
     */
    public function test_hsts_nincs_fejlesztes_kozben(): void
    {
        $this->get('/')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }
}
