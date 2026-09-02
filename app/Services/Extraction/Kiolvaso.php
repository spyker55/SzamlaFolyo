<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Enums\DokumentumAllapot;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Services\Files\FajlTarolo;
use App\Support\Adoszam;
use App\Support\Ido;
use App\Support\Osszeg;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Egy dokumentum kiolvasása: fájl → modell → ellenőrzés → mentés.
 *
 * A nyers választ **mindig** eltároljuk, akkor is, ha az értelmezés utána
 * elszáll. Prompt- vagy modellcsere után ez az egyetlen mód visszamérni, hogy
 * tényleg jobb lett-e.
 */
final class Kiolvaso
{
    public function __construct(
        private readonly OpenRouterKliens $kliens,
        private readonly FajlTarolo $tarolo,
    ) {}

    public function futtat(Document $dokumentum): void
    {
        $tartalom = $this->tarolo->tartalom($dokumentum);

        if ($tartalom === null) {
            $this->hibara($dokumentum, 'A dokumentum fájlja nem található.');

            return;
        }

        $ceg = $dokumentum->company;
        $kezdet = microtime(true);

        try {
            $valasz = $this->kliens->kiolvas(
                $tartalom,
                (string) $dokumentum->mime_type,
                (string) $dokumentum->original_filename,
                $ceg?->name,
                $ceg?->tax_number,
            );
        } catch (KiolvasasHiba $e) {
            $this->kiolvasasRogzites($dokumentum, null, null, $e->getMessage(), (int) ((microtime(true) - $kezdet) * 1000));
            $this->hibara($dokumentum, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->kiolvasasRogzites($dokumentum, null, null, $e->getMessage(), (int) ((microtime(true) - $kezdet) * 1000));
            $this->hibara($dokumentum, 'Váratlan hiba a kiolvasás közben.');

            report($e);

            return;
        }

        $tiszta = Sema::tisztit($valasz['fields']);
        $mezok = $this->normalizal($tiszta['mezok']);
        $bukott = Validatorok::bukottak($mezok);
        $konfidencia = Konfidencia::osszevon($tiszta['konfidencia'], $bukott, $mezok);

        DB::transaction(function () use ($dokumentum, $valasz, $mezok, $konfidencia, $tiszta, $kezdet): void {
            $kiolvasas = $this->kiolvasasRogzites(
                $dokumentum,
                $mezok,
                $konfidencia,
                null,
                (int) ((microtime(true) - $kezdet) * 1000),
                $valasz,
            );

            // A dokumentum oszlopai az **ember munkapéldánya**: innentől ezt
            // szerkeszti. A gépi érték a kiolvasás sorában marad, érintetlenül.
            $dokumentum->forceFill($mezok + [
                'tobb_irat_gyanu' => $tiszta['tobb_irat_gyanu'],
                'status' => DokumentumAllapot::EllenorzesreVar->value,
                'claimed_at' => null,
                'error' => null,
            ])->save();

            unset($kiolvasas);
        });
    }

    /**
     * A modell által adott értékek a mi alakunkra hozva: a dátum ÉÉÉÉ-HH-NN, az
     * összeg tizedespontos, a pénznem nagybetűs, az adószám formázott.
     */
    private function normalizal(array $mezok): array
    {
        foreach (['issue_date', 'fulfillment_date', 'due_date'] as $mezo) {
            $mezok[$mezo] = $mezok[$mezo] !== null ? Ido::datumErtelmez((string) $mezok[$mezo]) : null;
        }

        foreach (['net_amount', 'vat_amount', 'gross_amount'] as $mezo) {
            if ($mezok[$mezo] === null) {
                continue;
            }

            $eredmeny = Osszeg::ertelmez(is_string($mezok[$mezo]) ? $mezok[$mezo] : (float) $mezok[$mezo]);
            $mezok[$mezo] = $eredmeny->ok ? $eredmeny->ertek : null;
        }

        if (is_string($mezok['currency']) && $mezok['currency'] !== '') {
            $mezok['currency'] = strtoupper(substr(trim($mezok['currency']), 0, 3));
        }

        foreach (['supplier_tax_number', 'customer_tax_number'] as $mezo) {
            if (is_string($mezok[$mezo])) {
                $mezok[$mezo] = Adoszam::formaz($mezok[$mezo]);
            }
        }

        return $mezok;
    }

    private function kiolvasasRogzites(
        Document $dokumentum,
        ?array $mezok,
        ?array $konfidencia,
        ?string $hiba,
        int $idoMs,
        ?array $valasz = null,
    ): DocumentExtraction {
        $kiolvasas = new DocumentExtraction([
            'document_id' => $dokumentum->id,
            'model' => (string) config('openrouter.model'),
            'model_version' => $valasz['model'] ?? null,
            'prompt_version' => Prompt::VERZIO,
            'raw_response' => $valasz['raw'] ?? null,
            'fields' => $mezok,
            'confidence' => $konfidencia,
            'input_tokens' => $valasz['input_tokens'] ?? null,
            'output_tokens' => $valasz['output_tokens'] ?? null,
            'cost' => $valasz['cost'] ?? null,
            'duration_ms' => $idoMs,
            'error' => $hiba,
        ]);
        $kiolvasas->company_id = $dokumentum->company_id;
        $kiolvasas->save();

        return $kiolvasas;
    }

    /**
     * Hiba után: amíg van próbálkozás hátra, visszatesszük a sorba; utána
     * megáll, és a Beérkezőben látszik, mi történt. A néma újrapróbálkozás
     * végtelenségig égetné a pénzt.
     */
    private function hibara(Document $dokumentum, string $uzenet): void
    {
        $max = (int) config('szamlafolyo.extraction.max_attempts');

        $dokumentum->forceFill([
            'status' => $dokumentum->attempts >= $max
                ? DokumentumAllapot::Hiba->value
                : DokumentumAllapot::Feltoltve->value,
            'claimed_at' => null,
            'error' => $uzenet,
        ])->save();
    }
}
