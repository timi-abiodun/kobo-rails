<?php

declare(strict_types=1);

namespace App\Sweeping;

/**
 * Pure daily-sweep calculation: no DB, no clock, no HTTP.
 *
 * Check order (first match wins):
 *  1. Paused      An explicit trader instruction beats everything, so the log says
 *                 "paused" even on a zero-sales day.
 *  2. ZeroSales   takings == 0: skip; the bank is never called.
 *  3. Compute     floor(takings * rateBps / 10000), integer division. Floor means
 *                 the trader is never over-swept; the lost fraction is < 1 kobo.
 *  4. Cap         min(computed, dailyCap).
 *     Minimum     if the capped amount < minSweepKobo (includes 0): ZeroSweepAmount.
 *  5. Buffer      the balance may never drop below minBufferKobo. If the sweep would
 *                 breach it, reduce to (balance - buffer); if that is below
 *                 minSweepKobo (so also zero or negative): LowBuffer.
 *
 * Cap runs before buffer so the buffer only trims an already-capped amount, and
 * the skip reason names the real cause.
 *
 * Pause contract: paused if isPaused is true OR now < pausedUntil (strictly;
 * now == pausedUntil is NOT paused). A timed pause must set only pausedUntil.
 * If isPaused is also set, the expiry alone will not resume the merchant.
 */
final class DailySweepCalculator
{
    public function decide(SweepInput $input): SweepDecision
    {
        if ($this->isPaused($input)) {
            return SweepDecision::skip(SweepReason::Paused);
        }

        if ($input->takingsKobo === 0) {
            return SweepDecision::skip(SweepReason::ZeroSales);
        }

        // Max operand product is 1e15 * 500 = 5e17 < PHP_INT_MAX (guarded in SweepInput).
        $amount = intdiv($input->takingsKobo * $input->rateBps, 10_000);
        $limitedBy = null;

        if ($amount > $input->dailyCapKobo) {
            $amount = $input->dailyCapKobo;
            $limitedBy = SweepLimit::Cap;
        }

        if ($amount < $input->minSweepKobo) {
            return SweepDecision::skip(SweepReason::ZeroSweepAmount);
        }

        $headroom = $input->balanceKobo - $input->minBufferKobo;

        if ($amount > $headroom) {
            $amount = $headroom;
            $limitedBy = SweepLimit::Buffer;

            // minSweepKobo >= 1, so this also covers zero and negative headroom.
            if ($amount < $input->minSweepKobo) {
                return SweepDecision::skip(SweepReason::LowBuffer);
            }
        }

        return SweepDecision::execute($amount, $limitedBy);
    }

    /**
     *limitedBy (informational): Cap if the cap trimmed the amount, Buffer if the
    * buffer did, null if untrimmed. If both trimmed, Buffer wins: it was the
    * binding constraint.
     * @param SweepInput $input
     * @return bool
     */
    private function isPaused(SweepInput $input): bool
    {
        return $input->isPaused
            || ($input->pausedUntil !== null && $input->now < $input->pausedUntil);
    }
}