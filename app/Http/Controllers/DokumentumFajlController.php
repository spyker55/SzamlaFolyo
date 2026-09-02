<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Files\FajlTarolo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A feltöltött fájl kiszolgálása. A fájlok a webgyökéren kívül vannak, ezért
 * ez az egyetlen út hozzájuk — és minden kérésnél újra eldől, hogy a kérő
 * cégéhez tartozik-e az irat (a globális scope miatt más cég irata meg sem
 * található).
 */
final class DokumentumFajlController
{
    public function __invoke(Request $request, Document $dokumentum): Response
    {
        abort_unless($dokumentum->vanFajlja(), 404, 'Ehhez az irathoz már nincs fájl: az export után törlődött.');

        $tartalom = app(FajlTarolo::class)->tartalom($dokumentum);

        abort_if($tartalom === null, 404);

        return response($tartalom, 200, [
            'Content-Type' => (string) $dokumentum->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes((string) $dokumentum->original_filename).'"',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
