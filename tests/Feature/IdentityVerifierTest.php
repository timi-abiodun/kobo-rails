<?php

use App\Http\Requests\OnboardingIdentityRequest;
use App\Identity\Contracts\IdentityVerifier;
use App\Identity\Exceptions\IdentityProviderUnavailable;
use App\Identity\IdentityFailureReason;
use App\Identity\MockIdentityVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

beforeEach(fn () => config(['identity.driver' => 'mock']));

it('returns name and dob for a known BVN', function () {
    $r = app(IdentityVerifier::class)->verify('22200000001');

    expect($r->valid)->toBeTrue()
        ->and($r->fullName)->toBe('Adebayo Ogunleye')
        ->and($r->dateOfBirth->format('Y-m-d'))->toBe('1990-05-14');
});

it('accepts a matching NIN', function () {
    expect(app(IdentityVerifier::class)->verify('22200000001', '11100000001')->valid)->toBeTrue();
});

it('rejects failure cases', function (string $bvn, ?string $nin, IdentityFailureReason $reason) {
    $r = app(IdentityVerifier::class)->verify($bvn, $nin);

    expect($r->valid)->toBeFalse()->and($r->failureReason)->toBe($reason);
})->with([
    'unknown bvn'       => [MockIdentityVerifier::BVN_NOT_FOUND, null,          IdentityFailureReason::BvnNotFound],
    'unknown nin'       => ['22200000001', MockIdentityVerifier::NIN_NOT_FOUND, IdentityFailureReason::NinNotFound],
    'nin belongs to another bvn' => ['22200000001', '11100000002',              IdentityFailureReason::NinBvnMismatch],
]);

it('throws IdentityProviderUnavailable on mock outage, with a clean message', function () {
    try {
        app(IdentityVerifier::class)->verify(MockIdentityVerifier::BVN_OUTAGE);
        $this->fail('should have thrown');
    } catch (IdentityProviderUnavailable $e) {
        expect($e->getMessage())->not->toContain(MockIdentityVerifier::BVN_OUTAGE);
    }
});

it('maps Dojah 5xx to IdentityProviderUnavailable without leaking the BVN', function () {
    config(['identity.driver' => 'dojah', 'identity.dojah.app_id' => 'x', 'identity.dojah.secret_key' => 'y']);
    Http::fake(['*' => Http::response([], 503)]);

    try {
        app(IdentityVerifier::class)->verify('22200000001');
        $this->fail('should have thrown');
    } catch (IdentityProviderUnavailable $e) {
        expect($e->getMessage())->not->toContain('22200000001');
    }
});

it('maps Dojah connection failures without chaining the leaky previous exception', function () {
    config(['identity.driver' => 'dojah', 'identity.dojah.app_id' => 'x', 'identity.dojah.secret_key' => 'y']);
    Http::fake(fn () => throw new ConnectionException('cURL error ... https://api.dojah.io/x?bvn=22200000001'));

    try {
        app(IdentityVerifier::class)->verify('22200000001');
        $this->fail('should have thrown');
    } catch (IdentityProviderUnavailable $e) {
        expect($e->getMessage())->not->toContain('22200000001')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('validates bvn/nin format without echoing the input', function (array $data, bool $passes) {
    $req = new OnboardingIdentityRequest();
    $v = Validator::make($data, $req->rules(), $req->messages());

    expect($v->passes())->toBe($passes);

    foreach ($data as $value) {
        expect(json_encode($v->errors()->all()))->not->toContain((string) $value);
    }
})->with([
    'valid bvn only'      => [['bvn' => '22200000001'], true],
    'valid bvn + nin'     => [['bvn' => '22200000001', 'nin' => '11100000001'], true],
    'leading zeros kept'  => [['bvn' => '00200000001'], true],
    'bvn too short'       => [['bvn' => '2220000000'], false],
    'bvn has letters'     => [['bvn' => '2220000000a'], false],
    'bvn sent as number'  => [['bvn' => 22200000001], false],
    'nin too long'        => [['bvn' => '22200000001', 'nin' => '111000000012'], false],
    'bvn missing'         => [['nin' => '11100000001'], false],
]);