<?php

namespace App\NameMatching;

final readonly class StringScore
{
    public function __construct(
        public float $score,          // 0..1, before the single-token cap
        public int $exactTokens,      // aligned token pairs identical after normalisation
        public int $compatibleTokens, // aligned pairs with any credit (exact, fuzzy or initial)
        public bool $singleToken,     // shorter name has one token: can't verify both name parts
    ) {}

    /**
     * Order-agnostic proxy for "exact surname + matching given name/initial". We can't know which
     * token is the surname (SURNAME FIRSTNAME vs FIRSTNAME SURNAME), so: one exact token and at
     * least one more compatible token.
     */
    public function strongTokenAgreement(): bool
    {
        return $this->exactTokens >= 1 && $this->compatibleTokens >= 2;
    }
}