<?php

declare(strict_types=1);

namespace App\Waas;

use DateTimeInterface;
use InvalidArgumentException;

/** The only place key formats are defined. */
final class IdempotencyKeys
{
    private const MAX_LENGTH = 64; // TODO(unverified): provider's real limit/charset

    /**
     * Attempt 1 => "sweep:{merchant}:{date}", attempt n>1 => "sweep:{merchant}:{date}:{n}".
     *
     * A retry after a DEFINITIVE Failed must bump $attempt: the same key may replay the
     * original failure. After DebitOutcomeUnknown, do NOT bump: reconcile the same key via
     * getDebitStatus() first, and only bump if the lookup proves nothing moved and the
     * provider will accept a new key.
     *
     * Pass the same sweep_date value your teammate stores, so keys line up with (merchant_id, sweep_date).
     */
    public static function forSweep(string $merchantId, DateTimeInterface|string $sweepDate, int $attempt = 1): string
    {
        $date = $sweepDate instanceof DateTimeInterface ? $sweepDate->format('Y-m-d') : $sweepDate;

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Sweep date must be Y-m-d.');
        }

        return self::validated("sweep:{$merchantId}:{$date}".self::suffix($attempt));
    }

    /** Same rule as forSweep: bump $attempt only after the previous reversal definitively Failed. */
    public static function forReversal(string $originalKey, int $attempt = 1): string
    {
        return self::validated("rev:{$originalKey}".self::suffix($attempt));
    }

    private static function suffix(int $attempt): string
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Attempt must be >= 1.');
        }

        return $attempt === 1 ? '' : ":{$attempt}";
    }

    private static function validated(string $key): string
    {
        if (strlen($key) > self::MAX_LENGTH || ! preg_match('/^[A-Za-z0-9:_-]+$/', $key)) {
            throw new InvalidArgumentException('Invalid idempotency key format or length.');
        }

        return $key;
    }
}