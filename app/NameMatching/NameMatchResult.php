<?php

namespace App\NameMatching;

final readonly class NameMatchResult
{
    public function __construct(
        public bool $matched,
        public bool $needsReview,             // true implies matched === false
        public float $score,                  // string score, rounded to 4 dp
        public NameMatchMethod $method,       // what decided the outcome
        public float $highThreshold,
        public float $lowThreshold,
        public bool $strongTokenAgreement,
        public ?float $embeddingScore = null, // null until the embedding step runs (batch 3)
    ) {}

    /** The caller blocks onboarding on this. Includes needs-review results. */
    public function isMismatch(): bool
    {
        return ! $this->matched;
    }
}