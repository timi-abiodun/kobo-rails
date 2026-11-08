<?php

namespace App\NameMatching;

use InvalidArgumentException;

/** String-only matcher: a pure function of two strings (no I/O, no clock, no DB). */
final class NameMatcher
{
    public function __construct(
        private readonly NameNormalizer $normalizer,
        private readonly StringNameScorer $scorer,
        private readonly float $high,
        private readonly float $low,
    ) {
        if (! ($low >= 0.0 && $low < $high && $high <= 1.0)) {
            throw new InvalidArgumentException('Name match thresholds must satisfy 0 <= low < high <= 1.');
        }
    }

    /** @param array<string,mixed> $config contents of config/name_matching.php */
    public static function fromConfig(array $config): self
    {
        return new self(
            new NameNormalizer($config['titles'], $config['business_words'], $config['name_variants']),
            new StringNameScorer(
                (float) $config['token_floor'],
                (float) $config['initial_credit'],
                (float) $config['extra_token_penalty'],
            ),
            (float) $config['thresholds']['high'],
            (float) $config['thresholds']['low'],
        );
    }

    /** Space-joined normalised tokens. This (not the raw name) is what leaves the process for the scorer. */
    public function normalizedName(string $name): string
    {
        return implode(' ', $this->normalizer->tokens($name));
    }

    public function match(string $registeredName, string $bankName): NameMatchResult
    {
        $s = $this->scorer->score(
            $this->normalizer->tokens($registeredName),
            $this->normalizer->tokens($bankName),
        );

        $score = $s->score;

        if ($s->singleToken) {
            // One-word name vs anything: can't confirm both name parts, so it can never auto-pass.
            $score = min($score, ($this->low + $this->high) / 2);
        }

        $score = round($score, 4); // kill float noise at the boundaries

        [$matched, $review, $method] = match (true) {
            $score >= $this->high => [true, false, NameMatchMethod::StringHigh],
            $score <= $this->low  => [false, false, NameMatchMethod::StringLow],
            default               => [false, true, NameMatchMethod::StringReview],
        };

        return new NameMatchResult(
            $matched, $review, $score, $method, $this->high, $this->low, $s->strongTokenAgreement(),
        );
    }
}