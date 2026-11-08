<?php

declare(strict_types=1);

namespace App\Sweeping;

/**
 * Invariants: Skip has amountKobo 0 and limitedBy null; Execute has amountKobo >= 1.
 * limitedBy is informational only: which constraint trimmed an executed amount.
 */
final readonly class SweepDecision
{
    private function __construct(
        public SweepAction $action,
        public int $amountKobo,
        public SweepReason $reason,
        public ?SweepLimit $limitedBy,
    ) {
    }

    public static function execute(int $amountKobo, ?SweepLimit $limitedBy = null): self
    {
        if ($amountKobo < 1) {
            throw SweepInputException::outOfRange('amountKobo');
        }

        return new self(SweepAction::Execute, $amountKobo, SweepReason::Executed, $limitedBy);
    }

    public static function skip(SweepReason $reason): self
    {
        if ($reason === SweepReason::Executed) {
            throw SweepInputException::outOfRange('reason');
        }

        return new self(SweepAction::Skip, 0, $reason, null);
    }

    public function shouldCallBank(): bool
    {
        return $this->action === SweepAction::Execute;
    }
}