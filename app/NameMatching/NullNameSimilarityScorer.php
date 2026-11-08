<?php

namespace App\NameMatching;

final class NullNameSimilarityScorer implements NameSimilarityScorer
{
    public function score(string $registeredName, string $bankName): ?float
    {
        return null;
    }
}