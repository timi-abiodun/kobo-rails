<?php

namespace App\Identity;

use DateTimeImmutable;

final readonly class IdentityVerificationResult
{
    private function __construct(
        public bool $valid,
        public ?string $fullName,
        public ?DateTimeImmutable $dateOfBirth,
        public ?IdentityFailureReason $failureReason,
    ) {}

    public static function verified(string $fullName, DateTimeImmutable $dateOfBirth): self
    {
        return new self(true, $fullName, $dateOfBirth, null);
    }

    public static function rejected(IdentityFailureReason $reason): self
    {
        return new self(false, null, null, $reason);
    }
}