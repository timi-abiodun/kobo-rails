<?php

declare(strict_types=1);

namespace App\Waas\Data;

use App\Waas\Support\Mask;

final readonly class WaasAccount
{
    public function __construct(
        public string $nuban,       // 10 digits
        public string $walletId,
        public string $accountName,
    ) {}

    public function __debugInfo(): array
    {
        return [
            'nuban' => Mask::account($this->nuban),
            'walletId' => '***',
            'accountName' => $this->accountName,
        ];
    }
}