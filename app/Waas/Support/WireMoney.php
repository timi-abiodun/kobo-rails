<?php

declare(strict_types=1);

namespace App\Waas\Support;

use App\Waas\Http\WireFormat;

final class WireMoney
{
    /** @param int $kobo must be > 0 (callers assert) */
    public static function toWire(int $kobo): int|string
    {
        return WireFormat::AMOUNT_UNIT === 'kobo'
            ? $kobo
            : sprintf('%d.%02d', intdiv($kobo, 100), $kobo % 100);
    }

    /** Returns kobo, or null if the value isn't a recognisable amount. No float arithmetic. */
    public static function fromWire(mixed $value): ?int
    {
        $isKobo = WireFormat::AMOUNT_UNIT === 'kobo';

        if (is_int($value)) {
            return $isKobo ? $value : $value * 100;
        }

        if (is_float($value)) {
            if ($isKobo) {
                return null; // fractional kobo is malformed
            }
            $value = number_format($value, 2, '.', ''); // JSON decode boundary only; rounds to 2dp as text
        }

        if (! is_string($value) || ! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        if ($isKobo) {
            return ($m[3] ?? '') === '' ? (int) ($m[1].$m[2]) : null;
        }

        $kobo = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$kobo : $kobo;
    }
}