<?php

namespace App\NameMatching;

use Psr\Log\LoggerInterface;
use Throwable;

final class AccountNameVerifier
{
    public function __construct(
        private readonly NameMatcher $matcher,
        private readonly NameSimilarityScorer $scorer,
        private readonly LoggerInterface $log,
        private readonly float $embeddingLow, // embedding score at/below which a borderline result is mismatched
    ) {}

    public function verify(string $registeredName, string $bankName): NameMatchResult
    {
        $r = $this->matcher->match($registeredName, $bankName);

        // Clear string evidence (high or low) is final: the embedding service is never consulted,
        // so it can neither rescue a mismatch nor veto a match, and an outage can't affect these.
        if (! $r->needsReview) {
            return $r;
        }

        $e = $this->embeddingScore($registeredName, $bankName);

        if ($e === null) {
            return $r; // stays blocked: needs_review, method string_review
        }

        // The only path to "matched" through the embedding: borderline string score AND strong
        // token agreement AND a high embedding score. The embedding alone can never match.
        [$matched, $review, $method] = match (true) {
            ! $r->strongTokenAgreement && $e <= $this->embeddingLow => [false, false, NameMatchMethod::EmbeddingLow],
            default                                                 => [false, true, NameMatchMethod::EmbeddingReview],
        };

        $this->log->info('name_match_embedding', [ // scores only, never names
            'embedding_score' => $e,
            'string_score'    => $r->score,
            'method'          => $method->value,
        ]);

        return new NameMatchResult(
            $matched, $review, $r->score, $method, $r->highThreshold, $r->lowThreshold,
            $r->strongTokenAgreement, $e, $this->embeddingLow,
        );
    }

    private function embeddingScore(string $registeredName, string $bankName): ?float
    {
        try {
            $e = $this->scorer->score(
                $this->matcher->normalizedName($registeredName),
                $this->matcher->normalizedName($bankName),
            );
        } catch (Throwable $t) {
            $this->log->warning('name_scorer_unavailable', ['reason' => 'exception', 'class' => $t::class]);

            return null; // fail closed, never echo the message
        }

        // Defensive: a misbehaving scorer must not push a bogus number into a decision.
        return ($e !== null && is_finite($e) && $e >= 0.0 && $e <= 1.0) ? $e : null;
    }
}