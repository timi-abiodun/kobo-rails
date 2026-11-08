<?php

declare(strict_types=1);

namespace App\Waas\Data;

use App\Waas\Support\Mask;
use InvalidArgumentException;

final readonly class DebitDestination
{
    public function __construct(
        public string $accountNumber,
        public ?string $bankCode = null,     // TODO(unverified): may be required for non-Access destinations
        public ?string $accountName = null,
    ) {
        if ($accountNumber === '') {
            throw new InvalidArgumentException('Destination account must not be empty.');
        }
    }

    public function __debugInfo(): array
    {
        return [
            'accountNumber' => Mask::account($this->accountNumber),
            'bankCode' => $this->bankCode,
        ];
    }
}