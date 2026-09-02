<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Enums\DokumentumAllapot;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Egyetlen tölcsér, amin minden beérkező fájl átmegy — a böngészőből feltöltött
 * és az e-mail mellékleteként érkezett is. Azért egy helyen, hogy a
 * duplikátum-szabály és a méretkorlát ne csússzon szét a két út között.
 */
final class FajlTarolo
{
    public const LEMEZ = 'local';

    /**
     * @param  string  $tartalom  a fájl nyers bájtjai
     * @return Document a létrejött (esetleg duplikátumnak jelölt) dokumentum
     *
     * @throws FajlHiba ha a típus vagy a méret nem megengedett
     */
    public function tarol(
        Company $ceg,
        string $tartalom,
        string $eredetiNev,
        ?string $mime = null,
        string $forras = 'upload',
        ?int $feltoltoId = null,
        ?int $inboundEmailId = null,
    ): Document {
        $meret = strlen($tartalom);
        $maxMeret = (int) config('szamlafolyo.upload.max_bytes');

        if ($meret === 0) {
            throw new FajlHiba('A fájl üres.');
        }

        if ($meret > $maxMeret) {
            throw new FajlHiba(sprintf(
                'A fájl nagyobb a megengedettnél (%s MB a %s MB-os korlát helyett).',
                number_format($meret / 1048576, 1, ',', ' '),
                (int) ($maxMeret / 1048576),
            ));
        }

        // A kliens által küldött MIME-típust nem hisszük el: a tartalomból
        // állapítjuk meg, és csak azt fogadjuk el, amit a modell tud olvasni.
        $valodiMime = $this->mimeMegallapitas($tartalom) ?? $mime;
        $engedett = (array) config('szamlafolyo.upload.mime_types');

        if (! isset($engedett[$valodiMime])) {
            throw new FajlHiba('Csak PDF, JPG, PNG és WEBP fájlt tudunk feldolgozni.');
        }

        $sha256 = hash('sha256', $tartalom);

        // Ugyanaz a fájl már bent van? Nem dobjuk el — sorként megjelenik
        // duplikátumként, hogy látszódjon, mi történt vele, de nem kerül
        // feldolgozásra, és nem eszi a keretet.
        $eredeti = Document::query()
            ->where('company_id', $ceg->id)
            ->where('sha256', $sha256)
            ->where('status', '!=', DokumentumAllapot::Duplikatum->value)
            ->orderBy('id')
            ->first();

        $dokumentum = new Document([
            'status' => $eredeti ? DokumentumAllapot::Duplikatum->value : DokumentumAllapot::Feltoltve->value,
            'source' => $forras,
            'original_filename' => $this->biztonsagosNev($eredetiNev),
            'mime_type' => $valodiMime,
            'size_bytes' => $meret,
            'sha256' => $sha256,
            'uploaded_by' => $feltoltoId,
            'inbound_email_id' => $inboundEmailId,
            'duplicate_of_id' => $eredeti?->id,
        ]);
        $dokumentum->company_id = $ceg->id;
        $dokumentum->save();

        // A duplikátum az eredeti fájlra hivatkozik: nem írjuk le kétszer.
        if ($eredeti !== null) {
            $dokumentum->update(['storage_path' => $eredeti->storage_path]);

            return $dokumentum;
        }

        $utvonal = sprintf(
            'iratok/%d/%d/%s',
            $ceg->id,
            $dokumentum->id,
            $this->biztonsagosNev($eredetiNev, $engedett[$valodiMime]),
        );

        if (! Storage::disk(self::LEMEZ)->put($utvonal, $tartalom)) {
            // A sort nem töröljük: a hibás feltöltés is történés, és a
            // Beérkezőben látszania kell, hogy miért nincs meg az irat.
            $dokumentum->update([
                'status' => DokumentumAllapot::Hiba->value,
                'error' => 'A fájlt nem sikerült elmenteni a tárhelyre.',
            ]);

            throw new RuntimeException('A fájl mentése nem sikerült.');
        }

        $dokumentum->update(['storage_path' => $utvonal]);

        return $dokumentum;
    }

    public function tartalom(Document $dokumentum): ?string
    {
        if (! $dokumentum->vanFajlja()) {
            return null;
        }

        $lemez = Storage::disk(self::LEMEZ);

        return $lemez->exists($dokumentum->storage_path) ? $lemez->get($dokumentum->storage_path) : null;
    }

    /**
     * A fájl törlése az exportnál. A duplikátumok ugyanarra az útvonalra
     * mutatnak, ezért csak akkor törlünk a lemezről, ha rajtunk kívül már
     * senki nem hivatkozik rá.
     */
    public function torol(Document $dokumentum): void
    {
        $utvonal = $dokumentum->storage_path;

        $dokumentum->forceFill([
            'file_deleted_at' => now(),
            'storage_path' => null,
        ])->save();

        if ($utvonal === null) {
            return;
        }

        $masHasznalja = Document::query()
            ->where('company_id', $dokumentum->company_id)
            ->where('storage_path', $utvonal)
            ->whereKeyNot($dokumentum->id)
            ->exists();

        if (! $masHasznalja) {
            Storage::disk(self::LEMEZ)->delete($utvonal);
        }
    }

    private function mimeMegallapitas(string $tartalom): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($tartalom);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function biztonsagosNev(string $nev, ?string $kiterjesztes = null): string
    {
        $alap = Str::of(pathinfo($nev, PATHINFO_FILENAME))
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-')
            ->limit(80, '')
            ->value();

        if ($alap === '') {
            $alap = 'irat';
        }

        $kiterjesztes ??= pathinfo($nev, PATHINFO_EXTENSION);
        $kiterjesztes = preg_replace('/[^a-z0-9]/i', '', (string) $kiterjesztes) ?: 'bin';

        return $alap.'.'.strtolower($kiterjesztes);
    }
}
