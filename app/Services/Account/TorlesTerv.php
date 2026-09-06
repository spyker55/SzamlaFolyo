<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\Company;

/**
 * Mi fog történni, ha ez a felhasználó törli a fiókját.
 *
 * Azért külön érték, mert **ugyanennek** kell megjelennie a képernyőn a
 * gomb fölött és lefutnia a gomb után. Ha a nézet maga számolgatná, hogy
 * elmegy-e a cég, akkor az ígéret és a tett két külön kódrészletből jönne,
 * és előbb-utóbb elcsúsznának — egy visszafordíthatatlan műveletnél ez a
 * legrosszabb fajta hiba.
 */
final readonly class TorlesTerv
{
    public function __construct(
        public ?Company $ceg,
        /** A cég is megszűnik, mert a felhasználó után nem marad tagja. */
        public bool $cegIsTorlodik,
        /** Hányan maradnak a cégben a felhasználón kívül. */
        public int $maradoTagok,
        public ?TorlesAkadaly $akadaly,
        /** Van-e mit lemondani a számlázónál. */
        public bool $vanElofizetes,
        public int $iratok,
    ) {}

    public function mehet(): bool
    {
        return $this->akadaly === null;
    }
}
