<?php

namespace App\Rails;

use Carbon\CarbonImmutable;
use App\Enums\PaymentChannel;

final class RailInflow
{
    public function __construct(
        public readonly string $reference,        // -> inflow_transactions.bank_reference
        public readonly string $merchantAccount,  // NUBAN
        public readonly int $amountKobo,
        public readonly PaymentChannel $channel,          // POS | NIP_TRANSFER | QR
        public readonly CarbonImmutable $paidAt,  // stored UTC
        public readonly ?string $terminalId = null,
        public readonly bool $simulated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'merchant_account' => $this->merchantAccount,
            'amount_kobo' => $this->amountKobo,
            'channel' => $this->channel->value,
            'terminal_id' => $this->terminalId,
            'paid_at' => $this->paidAt->toIso8601String(),
            'simulated' => $this->simulated,
        ];
    }
}
