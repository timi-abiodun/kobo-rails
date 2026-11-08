<?php

declare(strict_types=1);

namespace App\Waas\Data;

use App\Waas\Support\Mask;
use InvalidArgumentException;

final readonly class WalletRef
{
    private function __construct(
        public ?string $nuban,
        public ?string $walletId,
    ) {}

    public static function fromNuban(string $nuban): self
    {
        if (! preg_match('/^\d{10}$/', $nuban)) {
            throw new InvalidArgumentException('Invalid NUBAN: '.Mask::account($nuban));
        }

        return new self($nuban, null);
    }

    public static function fromWalletId(string $walletId): self
    {
        if ($walletId === '') {
            throw new InvalidArgumentException('Wallet id must not be empty.');
        }

        return new self(null, $walletId);
    }

    public function __debugInfo(): array
    {
        return [
            'nuban' => $this->nuban !== null ? Mask::account($this->nuban) : null,
            'walletId' => $this->walletId !== null ? '***' : null,
        ];
    }
}