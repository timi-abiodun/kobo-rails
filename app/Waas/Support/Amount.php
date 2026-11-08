<?php

declare(strict_types=1);

namespace App\Waas\Support;

use InvalidArgumentException;

final class Amount
{
    public static function assertPositiveKobo(int $amountKobo): void
    {
        if ($amountKobo <= 0) {
            throw new InvalidArgumentException("amountKobo must be a positive integer, got {$amountKobo}.");
        }
    }
}