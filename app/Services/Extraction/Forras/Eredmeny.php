<?php

declare(strict_types=1);

namespace App\Services\Extraction\Forras;

/** Amit a felderítés talált: honnan olvasható a bizonylat, és mi van kéznél. */
final class Eredmeny
{
    public function __construct(
        public readonly Jelleg $jelleg,
        public readonly ?string $xml = null,
        public readonly ?string $szoveg = null,
        public readonly int $szovegHossz = 0,
        public readonly ?string $hiba = null,
        /**
         * Hány oldalas a PDF. Nem a kiolvasáshoz kell, hanem a kerethez: a
         * költségünk oldalarányos, a vevő viszont dokumentumot vásárol —
         * lásd `App\Support\Kredit`. `null`, ha nem tudjuk (kép, XML,
         * értelmezhetetlen PDF).
         */
        public readonly ?int $oldalszam = null,
    ) {}

    /** Amit a dokumentum mellé eltárolunk — ebből lesz a statisztika. */
    public function naplo(): array
    {
        return array_filter([
            'jelleg' => $this->jelleg->value,
            'oldalszam' => $this->oldalszam,
            'szoveg_hossz' => $this->szovegHossz,
            'xml_bajt' => $this->xml !== null ? strlen($this->xml) : null,
            'hiba' => $this->hiba,
        ], static fn ($ertek) => $ertek !== null);
    }
}
