<?php

declare(strict_types=1);

namespace App\Services\Documents;

use RuntimeException;

/**
 * Az áthelyezés elutasításának indoka, magyarul, a felhasználónak.
 *
 * Külön kivétel, mert ezek az üzenetek **a képernyőre kerülnek**: az
 * áthelyezés minden feltétele olyasmi, amit a felhasználó meg tud érteni és
 * gyakran orvosolni is tud (előbb vond vissza a jóváhagyást, előbb töröld a
 * duplikátumot). Egy néma 403 itt csak találgatást szülne.
 */
final class AthelyezesHiba extends RuntimeException {}
