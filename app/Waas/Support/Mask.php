<?php

declare(strict_types=1);

namespace App\Waas\Support;

final class Mask
{
    /** "0123456789" -> "******6789". Use for any account number that reaches a message or log. */
    public static function account(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) >= 4 ? '******'.substr($digits, -4) : '****';
    }
}