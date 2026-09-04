<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Cronban a jó hír a némaság.
 *
 * A tárhely időzítője **minden kimenetet e-mailben küld el**. Az öt percenként
 * futó parancsok napi 288 levelet jelentenének, és két hét alatt mindenki
 * szűrőt tesz rájuk — utána a valódi hibáról szóló levél is a szűrőbe esik.
 * Ugyanaz a csapda, mint a mindig piros validátor: ami folyton szól, arra
 * senki nem figyel.
 *
 * A kézenfekvő megoldás — `> /dev/null` a cron sorban — **itt nem működik**:
 * a Laravel a hibaüzenetet is a standard kimenetre írja (a `$this->error()`
 * és a lefutó kivétel is), nem a hibacsatornára, tehát az átirányítás a bajt
 * is elnyelné. Ezért a parancsok maguk döntik el, mikor van mondanivalójuk.
 *
 * A megkülönböztetés a **terminál**: cronnak nincs, embernek van. Így a
 * parancssorból futtatva ugyanúgy kiírja az összegzést, mint eddig, cronban
 * viszont hallgat — hacsak nem hiba történt, mert azt mindig kiírja.
 */
trait CsendesCron
{
    /** Összegzés annak, aki nézi. Cronban néma. */
    private function osszegzes(string $uzenet): void
    {
        if ($this->emberNezi()) {
            $this->info($uzenet);
        }
    }

    /** Mellékes megjegyzés — csak terminálban. */
    private function megjegyzes(string $uzenet): void
    {
        if ($this->emberNezi()) {
            $this->line($uzenet);
        }
    }

    private function emberNezi(): bool
    {
        return $this->output->isVerbose()
            || (\defined('STDOUT') && \function_exists('stream_isatty') && @stream_isatty(STDOUT));
    }
}
