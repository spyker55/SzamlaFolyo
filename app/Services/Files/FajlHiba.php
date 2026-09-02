<?php

declare(strict_types=1);

namespace App\Services\Files;

use RuntimeException;

/** Amit a felhasználónak szó szerint ki lehet írni. */
final class FajlHiba extends RuntimeException {}
