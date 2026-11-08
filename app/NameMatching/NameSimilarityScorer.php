<?php

namespace App\NameMatching;

interface NameSimilarityScorer
{
    /**
     * Embedding similarity between two NORMALISED names, 0.0 to 1.0.
     *
     * Returns null when no score is available (timeout, outage, bad response, not configured).
     * Implementations must not throw for availability problems and must not log the names.
     */
    public function score(string $registeredName, string $bankName): ?float;
}