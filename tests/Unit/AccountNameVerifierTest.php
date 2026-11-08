<?php

use App\NameMatching\AccountNameVerifier;
use App\NameMatching\NameMatcher;
use App\NameMatching\NameMatchMethod;
use App\NameMatching\NameSimilarityScorer;
use App\NameMatching\NullNameSimilarityScorer;
use Psr\Log\NullLogger;

final class FixedNameScorer implements NameSimilarityScorer
{
    public int $calls = 0;

    /** @var list<array{string,string}> */
    public array $seen = [];

    public function __construct(private readonly mixed $answer) {}

    public function score(string $registeredName, string $bankName): ?float
    {
        $this->calls++;
        $this->seen[] = [$registeredName, $bankName];

        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}

function makeVerifier(NameSimilarityScorer $scorer): AccountNameVerifier
{
    return new AccountNameVerifier(
        NameMatcher::fromConfig(require dirname(__DIR__, 2).'/config/name_matching.php'),
        $scorer,
        new NullLogger(),
        0.50, // embedding low
    );
}

function brokenScorer(string $kind): NameSimilarityScorer
{
    return match ($kind) {
        'null implementation' => new NullNameSimilarityScorer(),
        'returns null'        => new FixedNameScorer(null),
        'throws'              => new FixedNameScorer(new RuntimeException('boom')),
        'above 1'             => new FixedNameScorer(1.5),
        'below 0'             => new FixedNameScorer(-0.1),
        'NaN'                 => new FixedNameScorer(NAN),
    };
}

// middle-band pairs (string score between 0.55 and 0.92)
const STRONG_PAIR = ['Adebayo Ogunleye', 'Adebanjo Ogunleye']; // exact surname + fuzzy given name: 0.875
const WEAK_PAIR   = ['A. O.', 'Adebayo Ogunleye'];             // initials only: 0.90, no exact token
const ONE_WORD    = ['Adebayo', 'Adebayo Ogunleye'];           // capped into the middle band

it('does not consult the scorer when the string evidence is clear', function (string $a, string $b, NameMatchMethod $method) {
    $scorer = new FixedNameScorer(0.99);
    $r = makeVerifier($scorer)->verify($a, $b);

    expect($scorer->calls)->toBe(0)
        ->and($r->method)->toBe($method)
        ->and($r->embeddingScore)->toBeNull();
})->with([
    'clear match'    => ['OGUNLEYE ADEBAYO', 'Adebayo Ogunleye', NameMatchMethod::StringHigh],
    'clear mismatch' => ['Adebayo Ogunleye', 'Adewale Ogunleye', NameMatchMethod::StringLow],
]);

it('stays blocked, with needs_review and string_review, when the scorer is unavailable', function (string $kind) {
    foreach ([STRONG_PAIR, WEAK_PAIR, ONE_WORD] as [$a, $b]) {
        $r = makeVerifier(brokenScorer($kind))->verify($a, $b);

        expect($r->matched)->toBeFalse()
            ->and($r->needsReview)->toBeTrue()
            ->and($r->isMismatch())->toBeTrue()
            ->and($r->method)->toBe(NameMatchMethod::StringReview)
            ->and($r->embeddingScore)->toBeNull();
    }
})->with(['null implementation', 'returns null', 'throws', 'above 1', 'below 0', 'NaN']);

it('never turns a borderline result into a match, whatever the embedding says', function (array $pair) {
    foreach ([0.0, 0.97, 1.0] as $e) {
        expect(makeVerifier(new FixedNameScorer($e))->verify(...$pair)->matched)->toBeFalse();
    }
})->with([
    'Adebayo/Adebanjo'    => [['Adebayo Ogunleye', 'Adebanjo Ogunleye']],
    'Ibrahim Lawal/Lawan' => [['Ibrahim Lawal', 'Ibrahim Lawan']],
    'initials only'       => [WEAK_PAIR],
    'one-word name'       => [ONE_WORD],
]);

it('never lets the embedding alone produce a match', function (array $pair) {
    $r = makeVerifier(new FixedNameScorer(1.0))->verify(...$pair);

    expect($r->matched)->toBeFalse()
        ->and($r->needsReview)->toBeTrue()
        ->and($r->method)->toBe(NameMatchMethod::EmbeddingReview);
})->with(['initials only' => [WEAK_PAIR], 'one-word name' => [ONE_WORD]]);

it('lets a very low embedding settle a weak borderline result as mismatched, but keeps conflicts in review', function () {
    $weakLow = makeVerifier(new FixedNameScorer(0.2))->verify(...WEAK_PAIR);
    expect($weakLow->matched)->toBeFalse()
        ->and($weakLow->needsReview)->toBeFalse()
        ->and($weakLow->method)->toBe(NameMatchMethod::EmbeddingLow);

    // strong string agreement but the embedding disagrees: conflicting signals go to a human
    $conflict = makeVerifier(new FixedNameScorer(0.2))->verify(...STRONG_PAIR);
    expect($conflict->needsReview)->toBeTrue()
        ->and($conflict->method)->toBe(NameMatchMethod::EmbeddingReview);
});

it('never makes anything the string matcher accepts less accepted, whatever the embedding says', function (string $a, string $b) {
    foreach ([null, 0.0, 1.0] as $embedding) {
        $r = makeVerifier(new FixedNameScorer($embedding))->verify($a, $b);

        expect($r->matched)->toBeTrue()->and($r->method)->toBe(NameMatchMethod::StringHigh);
    }
})->with([
    ['OGUNLEYE ADEBAYO', 'Adebayo Ogunleye'],
    ['A. Ogunleye', 'Adebayo Ogunleye'],
    ['Chukwuemeke Okonkwo', 'Chukwuemeka Okonkwo'],
]);

it('sends normalised names to the scorer, not the raw ones', function () {
    $scorer = new FixedNameScorer(0.5);
    makeVerifier($scorer)->verify('Mr. Adebayo OGUNLEYE', 'Adebanjo Ogunleye');

    expect($scorer->seen)->toBe([['adebayo ogunleye', 'adebanjo ogunleye']]);
});

it('produces an alert context with scores, method and thresholds, and no names', function () {
    $r = makeVerifier(new FixedNameScorer(0.97))->verify(...STRONG_PAIR);

    expect(array_keys($r->toAlertContext()))->toBe([
        'method', 'needs_review', 'score', 'high_threshold', 'low_threshold',
        'strong_token_agreement', 'embedding_score', 'embedding_low_max',
    ]);

    $mismatch = makeVerifier(new NullNameSimilarityScorer())->verify(...STRONG_PAIR)->toAlertContext();
    expect($mismatch['method'])->toBe('string_review')
        ->and($mismatch['needs_review'])->toBeTrue()
        ->and($mismatch['embedding_score'])->toBeNull();
});