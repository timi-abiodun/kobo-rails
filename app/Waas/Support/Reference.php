<?php

declare(strict_types=1);

namespace App\Waas\Support;

use InvalidArgumentException;

final class Reference
{
    /**
     * `D` makes `$` match only at the true end of the string (no trailing-newline pass).
     * The 11-digit rule is a tripwire against someone passing a BVN/NIN by mistake; it can't
     * detect an identity number embedded in a longer string (and a substring rule would
     * randomly reject real UUIDs). Message never echoes the value.
     */
    public static function assertValid(?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $reference) || preg_match('/^\d{11}$/D', $reference)) {
            throw new InvalidArgumentException('Invalid account reference: use a generated UUID, never an identity number.');
        }
    }
}