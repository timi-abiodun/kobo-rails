<?php

use App\NameMatching\AccountNameVerifier;
use App\NameMatching\NameMatchMethod;
use App\NameMatching\NameSimilarityScorer;
use App\NameMatching\NullNameSimilarityScorer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'name_matching.embedding.driver' => 'http',
        'name_matching.embedding.url'    => 'http://scorer.test',
        'name_matching.embedding.path'   => '/similarity',
        'name_matching.embedding.token'  => 'secret-token',
    ]);
});

it('returns the score from a healthy service and sends the expected request', function () {
    Http::fake(['scorer.test/*' => Http::response(['score' => 0.87])]);

    expect(app(NameSimilarityScorer::class)->score('adebayo ogunleye', 'adebanjo ogunleye'))->toBe(0.87);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://scorer.test/similarity'
        && $r['name_a'] === 'adebayo ogunleye'
        && $r['name_b'] === 'adebanjo ogunleye'
        && $r->hasHeader('Authorization', 'Bearer secret-token'));
});

it('accepts an integer score of 1', function () {
    Http::fake(['scorer.test/*' => Http::response(['score' => 1])]);

    expect(app(NameSimilarityScorer::class)->score('a b', 'a b'))->toBe(1.0);
});

it('returns null instead of throwing when the service misbehaves', function (int $status, mixed $body) {
    Http::fake(['scorer.test/*' => Http::response($body, $status)]);

    expect(app(NameSimilarityScorer::class)->score('adebayo ogunleye', 'adebanjo ogunleye'))->toBeNull();
})->with([
    'server error'  => [500, ['score' => 0.9]],
    'rate limited'  => [429, []],
    'missing score' => [200, []],
    'null score'    => [200, ['score' => null]],
    'string score'  => [200, ['score' => '0.9']],
    'above 1'       => [200, ['score' => 1.2]],
    'negative'      => [200, ['score' => -0.2]],
    'not json'      => [200, '<html>oops</html>'],
]);

it('returns null on a connection failure or timeout', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(app(NameSimilarityScorer::class)->score('adebayo ogunleye', 'adebanjo ogunleye'))->toBeNull();
});

it('wires the verifier end to end: stays blocked when the service is up and scores high', function () {
    Http::fake(['scorer.test/*' => Http::response(['score' => 0.97])]);

    $r = app(AccountNameVerifier::class)->verify('Adebayo Ogunleye', 'Adebanjo Ogunleye');

    Http::assertSentCount(1); // proves the HTTP scorer was actually reached

    expect($r->matched)->toBeFalse()
        ->and($r->needsReview)->toBeTrue()
        ->and($r->method)->toBe(NameMatchMethod::EmbeddingReview)
        ->and($r->embeddingScore)->toBe(0.97);
});

it('binds the null scorer for the null driver and rejects unknown drivers', function () {
    config(['name_matching.embedding.driver' => 'null']);
    expect(app(NameSimilarityScorer::class))->toBeInstanceOf(NullNameSimilarityScorer::class);

    config(['name_matching.embedding.driver' => 'nope']);
    expect(fn () => app(NameSimilarityScorer::class))->toThrow(InvalidArgumentException::class);
});