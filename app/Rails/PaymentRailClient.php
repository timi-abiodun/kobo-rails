<?php

namespace App\Rails;

use Carbon\CarbonInterface;
use App\Enums\PaymentChannel;

interface PaymentRailClient
{
    public function name(): string;

    /** @return RailInflow[] credits to $nuban in [$from, $to)*/
    public function listInflows(string $nuban, CarbonInterface $from, CarbonInterface $to): array;

    /** Sandbox/mock only: fire a customer payment. */
    public function simulatePayment(string $nuban, int $amountKobo, PaymentChannel $channel = PaymentChannel::POS, ?string $reference = null): RailInflow;

    public function health(): bool;
}