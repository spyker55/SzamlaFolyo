<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use RuntimeException;

/** Kiolvasási hiba, aminek az üzenete a felhasználónak is megmutatható. */
final class KiolvasasHiba extends RuntimeException {}
