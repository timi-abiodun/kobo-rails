<?php

declare(strict_types=1);

namespace App\Sweeping;

enum SweepReason: string
{
    case Executed = 'executed';
    case Paused = 'paused';
    case ZeroSales = 'zero_sales';
    case ZeroSweepAmount = 'zero_sweep_amount';
    case LowBuffer = 'low_buffer';

    /**
     * Maps onto daily_sweeps.status. Null means no status exists yet
     * (ZeroSweepAmount, LowBuffer): ask the ledger owner before persisting.
     */
    public function dailySweepStatus(): ?string
    {
        return match ($this) {
            self::Executed => 'EXECUTED',
            self::ZeroSales => 'SKIPPED_ZERO_SALES',
            self::Paused => 'SKIPPED_PAUSED',
            self::ZeroSweepAmount, self::LowBuffer => null,
        };
    }
}