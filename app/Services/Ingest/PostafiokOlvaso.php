<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Models\Company;
use App\Models\InboundEmail;
use App\Services\Files\FajlHiba;
use App\Services\Files\FajlTarolo;
use App\Support\Berlo;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * A beérkeztető postafiók kiolvasása.
 *
 * A tárhelyszolgáltató nem küld webhookot, ezért a cron néhány percenként
 * benéz a catch-all fiókba. Ez lassabb, mint egy webhook, de nem kell hozzá
 * semmi, amit a szolgáltató nem ad.
 *
 * Három szabály, amitől ez az út nem lesz biztonsági rés:
 *   1. a **címzett** tokenje dönti el a céget, soha nem a feladó;
 *   2. e-mailből érkező irat **soha nem kerül automatikusan jóváhagyásra**;
 *   3. a feldolgozás idempotens — ugyanaz a levél kétszer kézbesítve nem
 *      csinál második tételt.
 */
final class PostafiokOlvaso
{
    public function __construct(
        private readonly FajlTarolo $tarolo,
        private readonly Berlo $berlo,
    ) {}

    /** @return array{levelek: int, iratok: int, atugrott: int} */
    public function olvas(int $maxLevel = 25): array
    {
        $beallitas = (array) config('inbox.imap');

        if (($beallitas['host'] ?? '') === '' || ($beallitas['username'] ?? '') === '') {
            throw new \RuntimeException('A beérkeztető postafiók nincs beállítva.');
        }

        $kliens = (new ClientManager)->make([
            'host' => $beallitas['host'],
            'port' => $beallitas['port'],
            'encryption' => $beallitas['encryption'],
            'validate_cert' => $beallitas['validate_cert'],
            'username' => $beallitas['username'],
            'password' => $beallitas['password'],
            'protocol' => $beallitas['protocol'],
        ]);

        $kliens->connect();

        $mappa = $kliens->getFolderByPath((string) $beallitas['folder']);
        $levelek = $mappa->query()->unseen()->limit($maxLevel)->get();

        $osszes = 0;
        $iratok = 0;
        $atugrott = 0;

        foreach ($levelek as $level) {
            $osszes++;

            try {
                $iratok += $this->egyLevel($level);
            } catch (\Throwable $e) {
                $atugrott++;
                Log::error('Beérkeztetési hiba', ['uzenet' => $e->getMessage()]);
            }

            // Olvasottnak jelöljük akkor is, ha nem tudtuk hova tenni:
            // különben minden körben újra próbálkoznánk ugyanazzal.
            $level->setFlag('Seen');
            $this->athelyez($level, (string) $beallitas['processed_folder']);
        }

        $kliens->disconnect();

        return ['levelek' => $osszes, 'iratok' => $iratok, 'atugrott' => $atugrott];
    }

    /** @return int hány irat lett belőle */
    private function egyLevel(Message $level): int
    {
        $fejlecek = $this->fejlecek($level);

        $token = CimzettToken::kereses(
            $fejlecek,
            (string) config('inbox.mode'),
            (string) config('inbox.domain'),
            config('inbox.plus_address'),
        );

        if ($token === null) {
            // Nem tudjuk, kihez tartozik. Nem találgatunk: eldobjuk.
            return 0;
        }

        $ceg = Company::query()->where('inbox_token', $token)->first();

        if ($ceg === null) {
            return 0;
        }

        $uzenetId = (string) ($level->getMessageId()?->first() ?: 'no-id-'.md5((string) $level->getHeader()?->raw));

        return $this->berlo->nevében($ceg, function () use ($ceg, $level, $uzenetId, $fejlecek): int {
            // Idempotencia: a `(company_id, message_id)` egyedi, ezért ha ez a
            // levél már bejött, nem csinálunk belőle második tételt.
            $meglevo = InboundEmail::query()->where('message_id', $uzenetId)->first();

            if ($meglevo !== null && $meglevo->status !== 'elutasitva') {
                return 0;
            }

            $mellekletek = $this->mellekletek($level);

            $rekord = $meglevo ?? new InboundEmail(['message_id' => $uzenetId]);
            $rekord->fill([
                'from_address' => (string) ($fejlecek['from'] ?? ''),
                'subject' => mb_substr((string) $level->getSubject()?->first(), 0, 255),
                'attachment_count' => count($mellekletek),
                'status' => $mellekletek === [] ? 'nincs_melleklet' : 'erkezett',
                'received_at' => now(),
            ]);
            $rekord->company_id = $ceg->id;
            $rekord->save();

            $darab = 0;

            foreach ($mellekletek as $melleklet) {
                try {
                    $this->tarolo->tarol(
                        $ceg,
                        (string) $melleklet['tartalom'],
                        (string) $melleklet['nev'],
                        $melleklet['mime'],
                        'email',
                        null,
                        $rekord->id,
                    );
                    $darab++;
                } catch (FajlHiba) {
                    // Egy nem támogatott melléklet (aláírás-kép, docx) nem
                    // hiba: a levél többi melléklete ettől még jöhet.
                }
            }

            $rekord->update([
                'document_count' => $darab,
                'status' => $darab > 0 ? 'feldolgozva' : $rekord->status,
            ]);

            return $darab;
        });
    }

    /** @return array<int, array{nev: string, mime: ?string, tartalom: string}> */
    private function mellekletek(Message $level): array
    {
        $ki = [];

        foreach ($level->getAttachments() as $melleklet) {
            // Az `inline` mellékletek az aláírásban lévő logók — nem bizonylatok.
            if (strtolower((string) $melleklet->getDisposition()) === 'inline') {
                continue;
            }

            $tartalom = $melleklet->getContent();

            if (! is_string($tartalom) || $tartalom === '') {
                continue;
            }

            $ki[] = [
                'nev' => (string) ($melleklet->getName() ?: 'melleklet'),
                'mime' => $melleklet->getMimeType(),
                'tartalom' => $tartalom,
            ];
        }

        return $ki;
    }

    /** @return array<string, string> */
    private function fejlecek(Message $level): array
    {
        $ki = [];
        $fejlec = $level->getHeader();

        foreach (['to', 'cc', 'from', 'delivered-to', 'x-original-to', 'envelope-to', 'x-forwarded-to'] as $nev) {
            $ertek = $fejlec?->get($nev);

            if ($ertek === null) {
                continue;
            }

            $ki[$nev] = is_object($ertek) ? (string) $ertek : (string) $ertek;
        }

        return $ki;
    }

    private function athelyez(Message $level, string $mappa): void
    {
        if ($mappa === '') {
            return;
        }

        try {
            $level->move($mappa);
        } catch (\Throwable) {
            // Ha a mappa nem létezik, a levél az INBOX-ban marad olvasottként.
            // Ez nem indok arra, hogy az egész futás elszálljon.
        }
    }
}
