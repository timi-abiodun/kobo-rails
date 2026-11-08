<?php

use App\Http\Requests\OnboardingIdentityRequest;
use App\Identity\Contracts\IdentityVerifier;
use App\Identity\MockIdentityVerifier;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['identity.driver' => 'mock']);

    Route::post('/_test/identity', function (OnboardingIdentityRequest $request, IdentityVerifier $verifier) {
        $data = $request->validated();
        $verifier->verify($data['bvn'], $data['nin'] ?? null); // outage propagates to the 503 renderer

        return response()->json($data);
    });
});

it('strips spaces and hyphens from bvn/nin before validating', function (string $bvn, string $nin) {
    $this->postJson('/_test/identity', ['bvn' => $bvn, 'nin' => $nin])
        ->assertOk()
        ->assertExactJson(['bvn' => '22200000001', 'nin' => '11100000001']);
})->with([
    'hyphens'          => ['222-0000-0001', '111-0000-0001'],
    'spaces'           => ['222 0000 0001', '111 0000 0001'],
    'mixed + trailing' => [' 2220000-0001 ', '1110000 0001 '],
]);

it('does not strip anything else, so garbage still fails and is not echoed', function (string $bad) {
    $response = $this->postJson('/_test/identity', ['bvn' => $bad])
        ->assertStatus(422)
        ->assertJsonValidationErrors('bvn');

    expect($response->getContent())->not->toContain($bad);
})->with(['2220000.0001', '222_0000_0001', '2220000000a', '222-0000-001']);

it('returns a retryable 503 when the provider is down, without echoing the bvn', function () {
    $response = $this->postJson('/_test/identity', ['bvn' => MockIdentityVerifier::BVN_OUTAGE])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '30')
        ->assertJson(['error' => 'identity_provider_unavailable']);

    expect($response->getContent())->not->toContain(MockIdentityVerifier::BVN_OUTAGE);
});

it('does not treat a verification outage as an invalid identity (never a 422)', function () {
    $this->postJson('/_test/identity', ['bvn' => MockIdentityVerifier::BVN_OUTAGE])
        ->assertStatus(503)
        ->assertJsonMissingValidationErrors();
});