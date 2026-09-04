<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use App\Models\Company;
use App\Models\InboundEmail;
use App\Services\Files\FajlHiba;
use App\Services\Files\FajlTarolo;
use App\Support\Berlo;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
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

    /** @param  array<string, mixed>  $beallitas */
    private function kliens(array $beallitas): Client
    {
        if (($beallitas['host'] ?? '') === '' || ($beallitas['username'] ?? '') === '') {
            throw new \RuntimeException('A beérkeztető postafiók nincs beállítva (IMAP_HOST, IMAP_USERNAME).');
        }

        return (new ClientManager)->make([
            'host' => $beallitas['host'],
            'port' => $beallitas['port'],
            'encryption' => $beallitas['encryption'],
            'validate_cert' => $beallitas['validate_cert'],
            'username' => $beallitas['username'],
            'password' => $beallitas['password'],
            'protocol' => $beallitas['protocol'],
        ]);
    }

    /** @return array{levelek: int, iratok: int, atugrott: int} */
    public function olvas(int $maxLevel = 25): array
    {
        $beallitas = (array) config('inbox.imap');

        $kliens = $this->kliens($beallitas);
        $kliens->connect();

        $mappa = $kliens->getFolderByPath((string) $beallitas['folder']);
        $levelek = $mappa->query()->unseen()->limit($maxLevel)->get();

        $osszes = 0;
        $iratok = 0;
        $atugrott = 0;

        foreach ($levelek as $level) {
            $osszes++;

            $besorolatlan = false;

            try {
                $iratok += $this->egyLevel($level);
            } catch (BesorolatlanLevel $e) {
                $atugrott++;
                $besorolatlan = true;
                Log::warning('Besorolatlan levél', [
                    'ok' => $e->getMessage(),
                    'cimzettek' => $e->fejlecek,
                ]);
            } catch (\Throwable $e) {
                $atugrott++;
                Log::error('Beérkeztetési hiba', ['uzenet' => $e->getMessage()]);
            }

            // Olvasottnak jelöljük akkor is, ha nem tudtuk hova tenni:
            // különben minden körben újra próbálkoznánk ugyanazzal.
            $level->setFlag('Seen');

            // A besorolatlan levél **nem** a feldolgozottak közé megy: ott
            // elvegyülne, és pont azt nem lehetne megtalálni, ami elveszett.
            $this->athelyez($level, (string) $beallitas[$besorolatlan ? 'unmatched_folder' : 'processed_folder']);
        }

        $kliens->disconnect();

        return ['levelek' => $osszes, 'iratok' => $iratok, 'atugrott' => $atugrott];
    }

    /**
     * Mit lát a rendszer a postafiókban — módosítás nélkül.
     *
     * A „nem érkezik meg a számla" bejelentés öt különböző dolgot jelenthet, és
     * a különbség nem látszik a felületen: nem is jött be levél (akkor a
     * levelezés a hibás, nem az alkalmazás), rossz mappát nézünk, a címzettben
     * nincs token, a tokenhez nincs cég, vagy a melléklet nem támogatott
     * típus. Ez a lekérdezés mind az ötöt megkülönbözteti.
     *
     * Semmit nem jelöl olvasottnak és nem mozgat: a `--proba` futtatható
     * nyugodtan, akkor is, ha a cron már átment a fiókon.
     *
     * @return array{mappak: array<int, string>, mappa: string, talalt_mappa: bool, levelek: array<int, array<string, mixed>>}
     */
    public function diagnosztika(int $darab = 5): array
    {
        $beallitas = (array) config('inbox.imap');
        $kliens = $this->kliens($beallitas);
        $kliens->connect();

        $mappak = [];
        foreach ($kliens->getFolders(false) as $mappa) {
            $mappak[] = $mappa->full_name;
        }

        $utvonal = (string) $beallitas['folder'];
        $mappa = $kliens->getFolderByPath($utvonal, soft_fail: true);

        if ($mappa === null) {
            $kliens->disconnect();

            return ['mappak' => $mappak, 'mappa' => $utvonal, 'talalt_mappa' => false, 'levelek' => []];
        }

        $levelek = [];

        foreach ($mappa->query()->whereAll()->setFetchOrder('desc')->limit($darab)->get() as $level) {
            $fejlecek = $this->fejlecek($level);

            $token = CimzettToken::kereses(
                $fejlecek,
                (string) config('inbox.mode'),
                (string) config('inbox.domain'),
                config('inbox.plus_address'),
            );

            $mellekletek = [];
            foreach ($this->mellekletek($level) as $melleklet) {
                $mellekletek[] = $melleklet['nev'].' · '.($melleklet['mime'] ?: 'ismeretlen típus');
            }

            $levelek[] = [
                'targy' => (string) $level->getSubject()?->first(),
                'felado' => (string) ($fejlecek['from'] ?? '—'),
                'cimzettek' => $this->cimzettek($fejlecek),
                'token' => $token,
                'ceg' => $token === null ? null : Company::query()->where('inbox_token', $token)->value('name'),
                'mellekletek' => $mellekletek,
            ];
        }

        $kliens->disconnect();

        return ['mappak' => $mappak, 'mappa' => $utvonal, 'talalt_mappa' => true, 'levelek' => $levelek];
    }

    /**
     * A fejlécekben talált címek, egy sorban. Ez az a lista, amiben a tokent
     * kerestük — ha üres vagy nincs benne a beküldési cím, ott a hiba.
     *
     * @param  array<string, string>  $fejlecek
     */
    private function cimzettek(array $fejlecek): string
    {
        $cimek = [];

        foreach ($fejlecek as $nev => $ertek) {
            if ($nev === 'from') {
                continue;
            }

            preg_match_all('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/', (string) $ertek, $talalatok);
            $cimek = array_merge($cimek, $talalatok[0] ?? []);
        }

        return $cimek === [] ? '—' : implode(', ', array_unique($cimek));
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
            // Nem tudjuk, kihez tartozik. Nem találgatunk — de nem is
            // hallgatunk: enélkül a „nem érkezik meg a számla" bejelentésre
            // nincs mit megnézni.
            throw new BesorolatlanLevel('A címzettben nincs érvényes beküldési token.', $fejlecek);
        }

        $ceg = Company::query()->where('inbox_token', $token)->first();

        if ($ceg === null) {
            throw new BesorolatlanLevel("A(z) {$token} tokenhez nincs cég.", $fejlecek);
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

    /**
     * Aláírásban lévő logó-e a melléklet.
     *
     * Korábban minden `inline` mellékletet kizártunk. Ez hibás: több
     * levelezőprogram a **PDF-et is** `inline` diszpozícióval küldi, hogy a
     * levéltörzsben megjelenítse — így pont a számla veszett el. Csak a kép
     * gyanús, és az is csak akkor, ha inline.
     */
    public static function alairasKepe(?string $diszpozicio, ?string $mime): bool
    {
        return strtolower((string) $diszpozicio) === 'inline'
            && str_starts_with(strtolower((string) $mime), 'image/');
    }

    /** @return array<int, array{nev: string, mime: ?string, tartalom: string}> */
    private function mellekletek(Message $level): array
    {
        $ki = [];

        foreach ($level->getAttachments() as $melleklet) {
            if (self::alairasKepe($melleklet->getDisposition(), $melleklet->getMimeType())) {
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
