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
            'Content-Type' => $this->kiszolgaltTipus((string) $dokumentum->mime_type),
            'Content-Disposition' => 'inline; filename="'.addslashes((string) $dokumentum->original_filename).'"',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Amilyen típussal kiszolgáljuk — ami nem feltétlenül az, ami a fájl.
     *
     * Az XML-t **soha nem** adjuk ki XML típussal. Az irat az ellenőrző
     * képernyőn azonos eredetű iframe-ben jelenik meg, a böngésző pedig az
     * XML-t megjeleníti — egy `<?xml-stylesheet?>` utasítással beküldött fájl
     * így a mi nevünkben futtatna szkriptet. A beküldés e-mailen keresztül
     * hitelesítés nélkül is nyitva áll, tehát ezt fel kell tételezni.
     *
     * Sima szövegként (a `nosniff` mellett) semmi nem fut belőle, olvasni
     * viszont ugyanúgy lehet — egy e-számlánál ez amúgy is hasznosabb nézet.
     */
    private function kiszolgaltTipus(string $mime): string
    {
        return str_contains($mime, 'xml') ? 'text/plain; charset=UTF-8' : $mime;
    }
}
