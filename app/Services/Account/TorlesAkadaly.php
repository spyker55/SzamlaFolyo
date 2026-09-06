<?php

declare(strict_types=1);

namespace App\Services\Account;

use RuntimeException;

/**
 * A törlés megállt, mert a végén rosszabb állapot maradna, mint most.
 *
 * Nem hiba a szó szokásos értelmében: a rendszer működik, a kérés érthető,
 * csak épp nem szabad teljesíteni. Az üzenet ezért olyan, hogy a
 * felhasználónak közvetlenül megmutatható — ő tudja feloldani, nem mi.
 */
final class TorlesAkadaly extends RuntimeException
{
    public static function utolsoTulajdonos(int $maradoTagok): self
    {
        return new self(sprintf(
            'Te vagy a cég egyetlen tulajdonosa, és rajtad kívül még %d felhasználó dolgozik benne. '
            .'A fiókod törlésével a cég gazdátlan maradna: nem lenne, aki a tagokat kezelje vagy az '
            .'előfizetést lemondja. Előbb vegyél fel valakit tulajdonosnak, vagy távolítsd el a többi tagot.',
            $maradoTagok,
        ));
    }

    public static function szamlazoNemErheto(): self
    {
        return new self('A Stripe-ot most nem sikerült elérni, ezért nem törlünk semmit — '
            .'különben a törölt fiók után is futna az előfizetés. Próbáld meg pár perc múlva.');
    }
}
