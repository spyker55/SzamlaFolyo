<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extraction\OpenRouterKliens;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A bizonylat csak olyan szolgáltatóhoz mehet, amelyik nem tárolja.
 *
 * A kiolvasáshoz az irat tartalma elhagyja a szervert, és **idegen cégek
 * adatait viszi magával** — nem a miénket. Az OpenRouter útválasztásában
 * ezért ki van kötve, hogy adatot megőrző szolgáltatóhoz ne kerülhessen; az
 * adatkezelési tájékoztató pedig ezt ígéretként ki is mondja.
 *
 * Ez az állítás a kimenő kérés törzsét nézi, nem a kódot: egy átszervezés
 * után a mező akkor is a helyén kell legyen. Egy kimaradt sor itt nem
 * fatállal jár, hanem azzal, hogy az adat csendben átmegy — és azt már nem
 * lehet visszakérni.
 */
final class ModellAdatvedelemTest extends TestCase
{
    public function test_a_keres_kizarja_az_adatot_megorzo_szolgaltatokat(): void
    {
        config(['openrouter.api_key' => 'teszt-kulcs']);

        Http::fake(['*/chat/completions' => Http::response($this->valasz())]);

        app(OpenRouterKliens::class)->kiolvas('%PDF-1.4 teszt', 'application/pdf', 'szamla.pdf');

        Http::assertSent(function (Request $keres): bool {
            $this->assertSame('deny', data_get($keres->data(), 'provider.data_collection'));

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function valasz(): array
    {
        return [
            'model' => 'teszt/modell-v1',
            'choices' => [[
                'message' => [
                    'tool_calls' => [[
                        'function' => [
                            'name' => 'record_extraction',
                            'arguments' => json_encode(['doc_type' => 'szamla']),
                        ],
                    ]],
                ],
            ]],
        ];
    }
}
