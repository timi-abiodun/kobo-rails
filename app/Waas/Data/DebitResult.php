<?php

declare(strict_types=1);

namespace App\Waas\Data;

use App\Waas\Enums\DebitFailureReason;
use App\Waas\Enums\DebitState;
use DateTimeImmutable;

final readonly class DebitResult
{
    public function __construct(
        public DebitState $state,
        public string $idempotencyKey,
        public ?int $amountKobo = null,
        public ?string $providerReference = null,
        public ?DebitFailureReason $failureReason = null,
        public ?DateTimeImmutable $processedAt = null, // UTC
    ) {}

    public static function succeeded(string $key, int $amountKobo, ?string $providerRef, ?DateTimeImmutable $at = null): self
    {
        return new self(DebitState::Succeeded, $key, $amountKobo, $providerRef, null, $at);
    }

    public static function failed(string $key, DebitFailureReason $reason, ?int $amountKobo = null): self
    {
        return new self(DebitState::Failed, $key, $amountKobo, null, $reason);
    }

    public static function pending(string $key): self
    {
        return new self(DebitState::Pending, $key);
    }

    public static function notFound(string $key): self
    {
        return new self(DebitState::NotFound, $key);
    }

    public function isSuccess(): bool
    {
        return $this->state === DebitState::Succeeded;
    }
}