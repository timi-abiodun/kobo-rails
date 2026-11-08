<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

use App\Waas\Enums\FaultCause;

/** Money may have moved. Reconcile via getDebitStatus($idempotencyKey) before ANY retry. */
final class DebitOutcomeUnknown extends WaasException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly FaultCause $cause,
    ) {
        parent::__construct(
            "WaaS debit outcome unknown ({$cause->value}); reconcile before retry. key={$idempotencyKey}"
        );
    }
}