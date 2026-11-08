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
        public ?float $embeddingScore = null,      // null = not consulted or unavailable
        public ?float $embeddingLowMax = null,
    ) {}

    /** The caller blocks onboarding on this. Includes needs-review results. */
    public function isMismatch(): bool
    {
        return ! $this->matched;
    }

    /** Safe to persist: scores, method and thresholds only. Never names, BVN or NIN. */
    public function toAlertContext(): array
    {
        return [
            'method'                 => $this->method->value,
            'needs_review'           => $this->needsReview,
            'score'                  => $this->score,
            'high_threshold'         => $this->highThreshold,
            'low_threshold'          => $this->lowThreshold,
            'strong_token_agreement' => $this->strongTokenAgreement,
            'embedding_score'        => $this->embeddingScore,
            'embedding_low_max'      => $this->embeddingLowMax,
        ];
    }
}