<?php

declare(strict_types=1);

use App\Waas\Data\DebitResult;
use App\Waas\Data\WaasAccount;
use App\Waas\Data\WalletRef;
use App\Waas\Enums\AccountCreationFailure;
use App\Waas\Enums\DebitFailureReason;
use App\Waas\Enums\DebitState;
use App\Waas\Exceptions\AccountCreationRejected;
use App\Waas\Exceptions\DebitOutcomeUnknown;
use App\Waas\Exceptions\WaasAccountNotFound;
use App\Waas\Exceptions\WaasCapabilityUnsupported;
use App\Waas\Exceptions\WaasMisconfigured;
use App\Waas\Exceptions\WaasUnavailable;
use App\Waas\Enums\FaultCause;
use App\Waas\Http\WireFormat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Support\WaasTestKit as Kit;

const WAAS_TEST_REF = 'a1b2c3d4-0000-4000-8000-000000000001';
const WAAS_TEST_REV_KEY = 'rev:sweep:m-1:2026-11-08';

// ------------------------------------------------------------------ createAccount

it('createAccount: returns the account and sends reference, bearer token and idempotency header once', function () {
    Kit::fakeJson([
        WireFormat::R_NUBAN => '0812948192',
        WireFormat::R_WALLET_ID => 'wlt_1',
        WireFormat::R_ACCOUNT_NAME => 'Blessing Okon (Kobo Kobo)',
    ], 201);

    $account = Kit::create(Kit::real(), reference: WAAS_TEST_REF);

    expect($account)->toBeInstanceOf(WaasAccount::class)
        ->and($account->nuban)->toBe('0812948192')
        ->and($account->walletId)->toBe('wlt_1')
        ->and($account->accountName)->toBe('Blessing Okon (Kobo Kobo)');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === Kit::BASE_URL.WireFormat::PATH_CREATE_ACCOUNT
        && $r->hasHeader('Authorization', 'Bearer '.Kit::API_KEY)
        && $r->hasHeader(WireFormat::IDEMPOTENCY_HEADER, WAAS_TEST_REF)
        && $r[WireFormat::F_ACCOUNT_REFERENCE] === WAAS_TEST_REF
        && $r[WireFormat::F_BVN] === Kit::BVN);
});

it('createAccount: omits the reference field and header when none is given', function () {
    Kit::fakeJson([WireFormat::R_NUBAN => '0812948192', WireFormat::R_WALLET_ID => 'w', WireFormat::R_ACCOUNT_NAME => 'A (Kobo Kobo)'], 201);

    Kit::create(Kit::real());

    Http::assertSent(fn (Request $r) => ! array_key_exists(WireFormat::F_ACCOUNT_REFERENCE, $r->data())
        && ! $r->hasHeader(WireFormat::IDEMPOTENCY_HEADER));
});

it('createAccount: rejects a malformed reference before sending anything', function (string $bad) {
    Kit::fakeJson([], 200);

    Kit::thrown(fn () => Kit::create(Kit::real(), reference: $bad), InvalidArgumentException::class, [$bad]);

    Http::assertNothingSent();
})->with(['empty' => [''], 'spaces' => ['has space'], 'bvn-like' => ['55555555555'], 'too long' => [str_repeat('a', 65)]]);

it('createAccount: 400/409/422 map to AccountCreationRejected, unknown codes to Rejected', function (int $status, ?string $code, AccountCreationFailure $expected) {
    Kit::fakeJson(array_filter([WireFormat::R_FAILURE_CODE => $code, 'data.message' => Kit::BODY_MARKER]), $status);

    $e = Kit::thrown(fn () => Kit::create(Kit::real()), AccountCreationRejected::class);

    expect($e->reason)->toBe($expected);
    Http::assertSentCount(1);
})->with([
    '400 duplicate bvn' => [400, 'duplicate_bvn', AccountCreationFailure::DuplicateBvn],
    '409 duplicate bvn' => [409, 'duplicate_bvn', AccountCreationFailure::DuplicateBvn],
    '422 invalid bvn' => [422, 'invalid_bvn', AccountCreationFailure::InvalidIdentity],
    '422 invalid nin' => [422, 'invalid_nin', AccountCreationFailure::InvalidIdentity],
    '422 unknown code' => [422, 'brand_new_code', AccountCreationFailure::Rejected],
    '400 no code' => [400, null, AccountCreationFailure::Rejected],
]);

it('createAccount: 401/403/404/405/415 are WaasMisconfigured, not business rejections', function (int $status) {
    Kit::fakeJson(['data.message' => Kit::BODY_MARKER], $status);

    Kit::thrown(fn () => Kit::create(Kit::real()), WaasMisconfigured::class);

    Http::assertSentCount(1);
})->with([401, 403, 404, 405, 415]);

it('createAccount: outages and junk throw retryable WaasUnavailable and never retry', function (string $scenario, FaultCause $cause) {
    $calls = Kit::fake($scenario);

    $e = Kit::thrown(fn () => Kit::create(Kit::real()), WaasUnavailable::class);

    expect($e->operation)->toBe('createAccount')->and($e->cause)->toBe($cause);
    //Http::assertSentCount(1);
    expect($calls)->toHaveCount(1);
})->with(Kit::dataset(Kit::TRANSIENT));

it('createAccount: a 2xx with a bad NUBAN or missing fields is malformed, not success', function (array $dot) {
    Kit::fakeJson($dot, 201);

    $e = Kit::thrown(fn () => Kit::create(Kit::real()), WaasUnavailable::class);

    expect($e->cause)->toBe(FaultCause::MalformedResponse);
    Http::assertSentCount(1);
})->with([
    'short nuban' => [[WireFormat::R_NUBAN => '12345', WireFormat::R_WALLET_ID => 'w', WireFormat::R_ACCOUNT_NAME => 'A']],
    'no wallet' => [[WireFormat::R_NUBAN => '0812948192', WireFormat::R_ACCOUNT_NAME => 'A']],
    'no name' => [[WireFormat::R_NUBAN => '0812948192', WireFormat::R_WALLET_ID => 'w']],
]);

// ------------------------------------------------------------------ debitWallet

it('debitWallet: success sends an integer-kobo amount, the key (header + body) and the source NUBAN', function () {
    Kit::fakeJson([WireFormat::R_STATUS => 'success', WireFormat::R_PROVIDER_REF => 'TX1'], 200);

    $r = Kit::debit(Kit::real());

    expect($r->state)->toBe(DebitState::Succeeded)
        ->and($r->amountKobo)->toBe(50_000)
        ->and($r->providerReference)->toBe('TX1')
        ->and($r->idempotencyKey)->toBe(Kit::KEY);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r[WireFormat::F_AMOUNT] === 50_000
        && $r[WireFormat::F_SOURCE_NUBAN] === Kit::SRC_NUBAN
        && $r[WireFormat::F_REFERENCE] === Kit::KEY
        && $r->hasHeader(WireFormat::IDEMPOTENCY_HEADER, Kit::KEY));
});

it('debitWallet: a wallet-id source uses the wallet field, not the NUBAN field', function () {
    Kit::fakeJson([WireFormat::R_STATUS => 'success'], 200);

    Kit::real()->debitWallet(WalletRef::fromWalletId('wlt_abc'), 1_000, new App\Waas\Data\DebitDestination(Kit::DEST), Kit::KEY, 'n');

    Http::assertSent(fn (Request $r) => $r[WireFormat::F_SOURCE_WALLET] === 'wlt_abc'
        && ! array_key_exists(WireFormat::F_SOURCE_NUBAN, $r->data()));
});

it('debitWallet: clear rejections are Failed with the right reason', function (int $status, ?string $code, DebitFailureReason $reason) {
    Kit::fakeJson(array_filter([WireFormat::R_STATUS => 'failed', WireFormat::R_FAILURE_CODE => $code]), $status);

    $r = Kit::debit(Kit::real());

    expect($r->state)->toBe(DebitState::Failed)->and($r->failureReason)->toBe($reason);
    Http::assertSentCount(1);
})->with([
    '422 insufficient' => [422, 'insufficient_funds', DebitFailureReason::InsufficientFunds],
    '422 invalid account' => [422, 'invalid_account', DebitFailureReason::InvalidAccount],
    '400 account not found' => [400, 'account_not_found', DebitFailureReason::InvalidAccount],
    '422 rejected' => [422, 'rejected', DebitFailureReason::Rejected],
    '422 failed, no code' => [422, null, DebitFailureReason::Rejected],
    '200 insufficient' => [200, 'insufficient_funds', DebitFailureReason::InsufficientFunds],
    '200 failed, no code' => [200, null, DebitFailureReason::Rejected],
]);

it('debitWallet: a 4xx with a recognised code but no status is still Failed', function () {
    Kit::fakeJson([WireFormat::R_FAILURE_CODE => 'insufficient_funds'], 422);

    expect(Kit::debit(Kit::real())->failureReason)->toBe(DebitFailureReason::InsufficientFunds);
});

it('debitWallet: every ambiguous outcome throws DebitOutcomeUnknown with the key, never Failed', function (string $scenario, FaultCause $cause) {
    $calls = Kit::fake($scenario);

    $e = Kit::thrown(fn () => Kit::debit(Kit::real()), DebitOutcomeUnknown::class);

    expect($e->idempotencyKey)->toBe(Kit::KEY)->and($e->cause)->toBe($cause);
    expect($calls)->toHaveCount(1);
})->with(Kit::dataset(Kit::UNKNOWN));

it('debitWallet: never retries, even when a retry would have succeeded', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()
        ->push(Arr::undot(['data.message' => Kit::BODY_MARKER]), 503)
        ->push(Arr::undot([WireFormat::R_STATUS => 'success']), 200)]);

    Kit::thrown(fn () => Kit::debit(Kit::real()), DebitOutcomeUnknown::class);

    Http::assertSentCount(1);
});

it('debitWallet: amount <= 0 is rejected before any request', function (int $amount) {
    Kit::fakeJson([WireFormat::R_STATUS => 'success'], 200);

    Kit::thrown(fn () => Kit::debit(Kit::real(), $amount), InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([0, -1, -50_000]);

it('debitWallet: 401/403 are WaasMisconfigured (loud), not an outcome', function (int $status) {
    Kit::fakeJson(['data.message' => Kit::BODY_MARKER], $status);

    Kit::thrown(fn () => Kit::debit(Kit::real()), WaasMisconfigured::class);

    Http::assertSentCount(1);
})->with([401, 403]);

// ------------------------------------------------------------------ reverseDebit

it('reverseDebit: throws WaasCapabilityUnsupported unless enabled, and sends nothing', function () {
    Kit::fakeJson([], 200);

    Kit::thrown(fn () => Kit::real(['reversal' => false])->reverseDebit(Kit::KEY, WAAS_TEST_REV_KEY, 'dispute'), WaasCapabilityUnsupported::class);

    Http::assertNothingSent();
});

it('reverseDebit: success sends the original and reversal references', function () {
    Kit::fakeJson([WireFormat::R_STATUS => 'success', WireFormat::R_PROVIDER_REF => 'RV1'], 200);

    $r = Kit::real()->reverseDebit(Kit::KEY, WAAS_TEST_REV_KEY, 'dispute');

    expect($r->state)->toBe(DebitState::Succeeded)->and($r->idempotencyKey)->toBe(WAAS_TEST_REV_KEY);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r[WireFormat::F_ORIGINAL_REF] === Kit::KEY
        && $r[WireFormat::F_REFERENCE] === WAAS_TEST_REV_KEY
        && $r->hasHeader(WireFormat::IDEMPOTENCY_HEADER, WAAS_TEST_REV_KEY));
});

it('reverseDebit: clear rejections are Failed with reversal-specific reasons', function (string $code, DebitFailureReason $reason) {
    Kit::fakeJson([WireFormat::R_STATUS => 'failed', WireFormat::R_FAILURE_CODE => $code], 422);

    expect(Kit::real()->reverseDebit(Kit::KEY, WAAS_TEST_REV_KEY, 'dispute')->failureReason)->toBe($reason);
})->with([
    ['original_not_found', DebitFailureReason::OriginalNotFound],
    ['already_reversed', DebitFailureReason::AlreadyReversed],
    ['window_expired', DebitFailureReason::WindowExpired],
]);

it('reverseDebit: ambiguous outcomes carry the REVERSAL key, not the original', function (string $scenario) {
    $calls = Kit::fake($scenario);

    $e = Kit::thrown(fn () => Kit::real()->reverseDebit(Kit::KEY, WAAS_TEST_REV_KEY, 'dispute'), DebitOutcomeUnknown::class);

    expect($e->idempotencyKey)->toBe(WAAS_TEST_REV_KEY);
    expect($calls)->toHaveCount(1);
})->with(array_keys(Kit::UNKNOWN));

// ------------------------------------------------------------------ getBalance

it('getBalance: throws WaasCapabilityUnsupported unless enabled, and sends nothing', function () {
    Kit::fakeJson([], 200);

    Kit::thrown(fn () => Kit::real(['balance' => false])->getBalance(Kit::SRC_NUBAN), WaasCapabilityUnsupported::class);

    Http::assertNothingSent();
});

it('getBalance: returns integer kobo', function () {
    Kit::fakeJson([WireFormat::R_BALANCE => 150_000], 200);

    expect(Kit::real()->getBalance(Kit::SRC_NUBAN))->toBe(150_000);
    Http::assertSentCount(1);
});

it('getBalance: 404 is WaasAccountNotFound with a masked account only', function () {
    Kit::fakeJson(['data.message' => Kit::BODY_MARKER], 404);

    $e = Kit::thrown(fn () => Kit::real()->getBalance(Kit::SRC_NUBAN), WaasAccountNotFound::class);

    expect($e->getMessage())->toContain('******6789');
    Http::assertSentCount(1);
});

it('getBalance: a malformed NUBAN never reaches the URL', function () {
    Kit::fakeJson([], 200);

    Kit::thrown(fn () => Kit::real()->getBalance('../admin'), WaasAccountNotFound::class);

    Http::assertNothingSent();
});

it('getBalance: outages and junk throw WaasUnavailable', function (string $scenario, FaultCause $cause) {
    $calls = Kit::fake($scenario);

    $e = Kit::thrown(fn () => Kit::real()->getBalance(Kit::SRC_NUBAN), WaasUnavailable::class);

    expect($e->cause)->toBe($cause);
    expect($calls)->toHaveCount(1);
})->with(Kit::dataset(Kit::TRANSIENT));

it('getBalance: an unparseable balance is malformed, not zero', function () {
    Kit::fakeJson([WireFormat::R_BALANCE => 'abc'], 200);

    $e = Kit::thrown(fn () => Kit::real()->getBalance(Kit::SRC_NUBAN), WaasUnavailable::class);

    expect($e->cause)->toBe(FaultCause::MalformedResponse);
});

// ------------------------------------------------------------------ getDebitStatus

it('getDebitStatus: maps success/pending/failed and queries by key', function (string $status, DebitState $state) {
    Kit::fakeJson([WireFormat::R_STATUS => $status], 200);

    $r = Kit::real()->getDebitStatus(Kit::KEY);

    expect($r)->toBeInstanceOf(DebitResult::class)->and($r->state)->toBe($state);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), rawurlencode(Kit::KEY)));
})->with([['success', DebitState::Succeeded], ['pending', DebitState::Pending], ['failed', DebitState::Failed]]);

it('getDebitStatus: 404 is NotFound', function () {
    Kit::fakeJson(['data.message' => Kit::BODY_MARKER], 404);

    expect(Kit::real()->getDebitStatus(Kit::KEY)->state)->toBe(DebitState::NotFound);
});

it('getDebitStatus: a failed lookup throws, so the outcome stays unknown', function (string $scenario, FaultCause $cause) {
    $calls = Kit::fake($scenario);

    $e = Kit::thrown(fn () => Kit::real()->getDebitStatus(Kit::KEY), WaasUnavailable::class);

    expect($e->operation)->toBe('getDebitStatus')->and($e->cause)->toBe($cause);
    expect($calls)->toHaveCount(1);
})->with(Kit::dataset(Kit::TRANSIENT));