<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Az OpenRouter hívása. OpenAI-kompatibilis végpont, ezért nincs szükség SDK-ra
 * — egy HTTP kérés az egész.
 *
 * A kötött kimenetet egyetlen, kikényszerített függvényhívással érjük el: így a
 * válasz nem szabad szöveg, amiből JSON-t kellene bányászni, hanem eleve a mi
 * sémánk szerinti objektum.
 */
final class OpenRouterKliens
{
    /**
     * @param  string  $tartalom  a bizonylat nyers bájtjai
     * @return array{fields: array, raw: array, model: ?string, input_tokens: ?int, output_tokens: ?int, cost: ?float}
     */
    public function kiolvas(
        string $tartalom,
        string $mime,
        string $fajlnev,
        ?string $cegNev = null,
        ?string $cegAdoszam = null,
    ): array {
        $kulcs = (string) config('openrouter.api_key');

        if ($kulcs === '') {
            throw new KiolvasasHiba('Nincs beállítva az OpenRouter API-kulcs.');
        }

        $adatUrl = 'data:'.$mime.';base64,'.base64_encode($tartalom);

        // A PDF fájlként, a kép képként megy — a modellek ezt a két alakot
        // értik, és a kettő nem cserélhető fel.
        $resz = str_starts_with($mime, 'image/')
            ? ['type' => 'image_url', 'image_url' => ['url' => $adatUrl]]
            : ['type' => 'file', 'file' => ['filename' => $fajlnev, 'file_data' => $adatUrl]];

        $keres = [
            'model' => (string) config('openrouter.model'),

            // A bizonylat idegen cégek adatait viszi magával, ezért csak olyan
            // szolgáltatóhoz mehet, amelyik nem tárolja és nem tanul belőle.
            // Az OpenRouter a többit ilyenkor kihagyja az útválasztásból.
            //
            // **Nem kapcsolható ki .env-ből, és ez szándékos.** Az adatkezelési
            // tájékoztató ígéretet tesz erről; egy átbillenthető ígéret pedig
            // rosszabb, mint a semmilyen, mert az olvasó nem látja, épp melyik
            // állapotban van. Ha egyszer nem marad választható szolgáltató, a
            // kérés hibával áll meg — a dokumentum a Beérkezőben marad, és
            // újrapróbálható. Ez a helyes irány: a csendben átengedett adatot
            // már nem lehet visszakérni.
            'provider' => ['data_collection' => 'deny'],

            'messages' => [
                ['role' => 'system', 'content' => Prompt::rendszer($cegNev, $cegAdoszam)],
                ['role' => 'user', 'content' => [$resz, ['type' => 'text', 'text' => Prompt::felhasznalo()]]],
            ],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => Sema::FUGGVENY_NEV,
                    'description' => 'A bizonylatról leolvasott adatok rögzítése.',
                    'parameters' => Sema::toolSema(),
                ],
            ]],
            'tool_choice' => [
                'type' => 'function',
                'function' => ['name' => Sema::FUGGVENY_NEV],
            ],
            'max_tokens' => 2048,
            'usage' => ['include' => true],
        ];

        try {
            $valasz = Http::withToken($kulcs)
                ->withHeaders([
                    // Az OpenRouter ezekkel azonosítja a hívó alkalmazást.
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-Title' => 'SzamlaFolyo',
                ])
                ->timeout((int) config('openrouter.timeout'))
                ->post(config('openrouter.base_url').'/chat/completions', $keres);
        } catch (ConnectionException $e) {
            // A megszakadt kapcsolat a leggyakoribb hiba, és a felhasználót az
            // érdekli, hogy újra kell-e próbálnia: itt igen, mert a kérés el
            // sem jutott a szolgáltatóig.
            throw new KiolvasasHiba('Az AI-szolgáltató nem érhető el. A kiolvasás automatikusan újraindul.', previous: $e);
        }

        if ($valasz->failed()) {
            $uzenet = (string) ($valasz->json('error.message') ?? $valasz->body());

            throw new KiolvasasHiba(sprintf(
                'Az AI-szolgáltató hibát adott (HTTP %d): %s',
                $valasz->status(),
                mb_strimwidth($uzenet, 0, 300, '…'),
            ));
        }

        $test = $valasz->json();
        $hivas = $test['choices'][0]['message']['tool_calls'][0] ?? null;
        $argumentumok = $hivas['function']['arguments'] ?? null;

        if (! is_string($argumentumok)) {
            throw new KiolvasasHiba('A modell nem a kért alakban válaszolt.');
        }

        try {
            $mezok = json_decode($argumentumok, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new KiolvasasHiba('A modell válasza nem értelmezhető JSON.', previous: $e);
        }

        if (! is_array($mezok)) {
            throw new KiolvasasHiba('A modell válasza nem objektum.');
        }

        return [
            'fields' => $mezok,
            'raw' => $test,
            // Amit ténylegesen futtattak — nem feltétlenül az, amit kértünk.
            'model' => $test['model'] ?? null,
            'input_tokens' => isset($test['usage']['prompt_tokens']) ? (int) $test['usage']['prompt_tokens'] : null,
            'output_tokens' => isset($test['usage']['completion_tokens']) ? (int) $test['usage']['completion_tokens'] : null,
            'cost' => isset($test['usage']['cost']) ? (float) $test['usage']['cost'] : null,
        ];
    }
}
