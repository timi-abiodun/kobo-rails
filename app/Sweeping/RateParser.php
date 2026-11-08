<?php

declare(strict_types=1);

namespace App\Sweeping;

/** Converts a NUMERIC(4,2)-style percentage string into basis points. No floats. */
final class RateParser
{
    /** "2.00" -> 200, "1.5" -> 150, "5" -> 500. Rejects anything outside 1.00 to 5.00. */
    public static function toBps(string $percent): int
    {
        // 1. MUST use \z instead of $ to strictly reject trailing newlines (e.g. "2.00\n")
        if (preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?\z/', $percent, $m) !== 1) {
            // 2. The test explicitly asserts the string "Invalid sweep input:"
            throw new SweepInputException('Invalid sweep input: malformed rate format');
        }

        // Your original logic here is perfect. 
        // "1.5" -> $m[2] is "5" -> str_pad becomes "50" -> 150 bps.
        $bps = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        if ($bps < SweepInput::MIN_RATE_BPS || $bps > SweepInput::MAX_RATE_BPS) {
            throw new SweepInputException('Invalid sweep input: rate is out of range');
        }

        return $bps;
    }
}