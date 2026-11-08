<?php

declare(strict_types=1);

namespace App\Sweeping;

use DateTimeInterface;

/**
 * Validated input for one merchant-day. Invalid input cannot be constructed,
 * so validation always runs before any business rule (including pause).
 *
 * All money is integer kobo. The int type declarations only reject floats and
 * numeric strings if the CALLING file declares strict_types=1 (strict_types is
 * per calling file, not per class). Callers must declare it and cast database
 * values explicitly: PDO returns NUMERIC columns as strings, so convert the
 * rate with RateParser::toBps() and money with (int).
 *
 * `now` and `pausedUntil` must be built as explicit UTC, e.g.
 * new DateTimeImmutable($value, new DateTimeZone('UTC')). A timestamp string
 * without a zone is otherwise parsed in app.timezone, which is how a pause ends
 * an hour early or late.
 */
final readonly class SweepInput
{
    public const MIN_RATE_BPS = 100;   // 1.00%
    public const MAX_RATE_BPS = 500;   // 5.00%

    public const DEFAULT_DAILY_CAP_KOBO = 500_000;   // N5,000
    public const DEFAULT_MIN_BUFFER_KOBO = 100_000;  // N1,000
    public const DEFAULT_MIN_SWEEP_KOBO = 1;

    /**
     * Overflow guard: 1e15 kobo (N10 trillion) x 500 bps = 5e17, well under
     * PHP_INT_MAX (~9.22e18), so takings x rate can never overflow.
     */
    public const MAX_TAKINGS_KOBO = 1_000_000_000_000_000;

    public function __construct(
        public int $takingsKobo,
        public int $rateBps,
        public int $balanceKobo,
        public DateTimeInterface $now,
        public bool $isPaused = false,
        public ?DateTimeInterface $pausedUntil = null,
        public int $dailyCapKobo = self::DEFAULT_DAILY_CAP_KOBO,
        public int $minBufferKobo = self::DEFAULT_MIN_BUFFER_KOBO,
        public int $minSweepKobo = self::DEFAULT_MIN_SWEEP_KOBO,
    ) {
        foreach ([
            'takingsKobo' => $takingsKobo,
            'balanceKobo' => $balanceKobo,
            'dailyCapKobo' => $dailyCapKobo,
            'minBufferKobo' => $minBufferKobo,
        ] as $field => $value) {
            if ($value < 0) {
                throw SweepInputException::negative($field);
            }
        }

        if ($takingsKobo > self::MAX_TAKINGS_KOBO) {
            throw SweepInputException::outOfRange('takingsKobo');
        }

        if ($rateBps < self::MIN_RATE_BPS || $rateBps > self::MAX_RATE_BPS) {
            throw SweepInputException::outOfRange('rateBps');
        }

        if ($minSweepKobo < 1) {
            throw SweepInputException::outOfRange('minSweepKobo');
        }
    }
}