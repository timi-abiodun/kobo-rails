<?php

declare(strict_types=1);

namespace App\Sweeping;

use InvalidArgumentException;

/** Names the offending field only, never the value (amounts are financial data). */
final class SweepInputException extends InvalidArgumentException
{
    public static function negative(string $field): self
    {
        return new self("Invalid sweep input: {$field} must not be negative.");
    }

    public static function outOfRange(string $field): self
    {
        return new self("Invalid sweep input: {$field} is out of range.");
    }

    public static function malformed(string $field): self
    {
        return new self("Invalid sweep input: {$field} is malformed.");
    }
}