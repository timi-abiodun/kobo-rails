<?php

namespace App\NameMatching;

final class StringNameScorer
{
    public function __construct(
        private readonly float $tokenFloor = 0.75,
        private readonly float $initialCredit = 0.90,
        private readonly float $extraTokenPenalty = 0.05,
    ) {}

    /**
     * @param list<string> $a normalised tokens
     * @param list<string> $b normalised tokens
     */
    public function score(array $a, array $b): StringScore
    {
        $n = min(count($a), count($b));
        $m = max(count($a), count($b));

        if ($n === 0) {
            return new StringScore(0.0, 0, 0, false);
        }

        $pairs = [];
        foreach ($a as $i => $ta) {
            foreach ($b as $j => $tb) {
                $sim = $this->tokenSimilarity($ta, $tb);
                if ($sim > 0.0) {
                    $pairs[] = [$sim, $i, $j];
                }
            }
        }

        // Greedy alignment, best pair first. Order-agnostic, so reordered names align naturally.
        usort($pairs, fn (array $x, array $y) => $y[0] <=> $x[0]);

        $usedA = $usedB = [];
        $sum = 0.0;
        $exact = $compatible = 0;

        foreach ($pairs as [$sim, $i, $j]) {
            if (isset($usedA[$i]) || isset($usedB[$j])) {
                continue;
            }
            $usedA[$i] = $usedB[$j] = true;
            $sum += $sim;
            $compatible++;
            if ($sim === 1.0) {
                $exact++;
            }
        }

        // Everything in the shorter name must be explained by the longer one; extras cost a little.
        $score = ($sum / $n) * max(0.0, 1 - $this->extraTokenPenalty * ($m - $n));

        return new StringScore($score, $exact, $compatible, $n === 1);
    }

    private function tokenSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $la = strlen($a);
        $lb = strlen($b);

        if ($la === 1 && $lb === 1) {
            return 0.0;
        }

        if ($la === 1 || $lb === 1) {
            [$initial, $other] = $la === 1 ? [$a, $b] : [$b, $a];

            return str_starts_with($other, $initial) ? $this->initialCredit : 0.0;
        }

        // Byte-level levenshtein is safe: the normaliser guarantees tokens are plain a–z.
        $sim = 1 - levenshtein($a, $b) / max($la, $lb);

        return $sim >= $this->tokenFloor ? $sim : 0.0;
    }
}