<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

use App\Waas\Enums\FaultCause;

/** Retryable. For debits use DebitOutcomeUnknown instead; never this. */
final class WaasUnavailable extends WaasException
{
    public function __construct(
        public readonly string $operation,
        public readonly FaultCause $cause,
    ) {
        parent::__construct("WaaS unavailable during {$operation} ({$cause->value}); retryable.");
    }
}