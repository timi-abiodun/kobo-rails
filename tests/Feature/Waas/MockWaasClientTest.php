<?php

declare(strict_types=1);

use App\Identity\IdentityHasher;
use App\Waas\Data\WalletRef;
use App\Waas\Enums\AccountCreationFailure;
use App\Waas\Enums\DebitFailureReason;
use App\Waas\Enums\DebitState;
use App\Waas\Enums\FaultCause;
use App\Waas\Exceptions\AccountCreationRejected;
use App\Waas\Exceptions\DebitOutcomeUnknown;
use App\Waas\Exceptions\WaasAccountNotFound;
use App\Waas\Exceptions\WaasUnavailable;
use App\Waas\IdempotencyKeys;
use App\Waas\WaasClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WaasTestKit as Kit;

uses(RefreshDatabase::class);

const WAAS_TEST_START_KOBO = 5_000_000; // waas.mock.starting_balance_kobo default

beforeEach(function () {
    /** @var \Tests\TestCase $this */
    config(['waas.driver' => 'mock']);
    app()->forgetInstance(WaasClient::class);
    $this->client = app(WaasClient::class);
});

// ------------------------------------------------------------------ debits & idempotency

it('replays the original result for the same key and debits once', function () {
    $c = $this->client;

    $first = Kit::debit($c, 100_000);
    $second = Kit::debit($c, 100_000);

    expect($first->state)->toBe(DebitState::Succeeded)
        ->and($second->state)->toBe(DebitState::Succeeded)
        ->and($second->providerReference)->toBe($first->providerReference)
        ->and($second->amountKobo)->toBe(100_000)
        ->and($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO - 100_000)
        ->and(DB::table('mock_waas_debits')->count())->toBe(1);
});

it('throws when the same key is reused with different parameters', function () {
    $c = $this->client;
    Kit::debit($c, 100_000);

    Kit::thrown(fn () => Kit::debit($c, 200_000), InvalidArgumentException::class);
    Kit::thrown(fn () => Kit::debit($c, 100_000, Kit::KEY, Kit::OTHER_NUBAN), InvalidArgumentException::class);

    expect($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO - 100_000);
});

it('fails clearly for trigger NUBANs and unknown wallets', function (string $nuban, DebitFailureReason $reason) {
    $r = Kit::debit($this->client, 10_000, Kit::KEY, $nuban);

    expect($r->state)->toBe(DebitState::Failed)->and($r->failureReason)->toBe($reason);
})->with([
    'insufficient funds trigger' => ['0000000001', DebitFailureReason::InsufficientFunds],
    'invalid account trigger' => ['0000000002', DebitFailureReason::InvalidAccount],
]);

it('fails with InvalidAccount for an unknown wallet id and debits a real one by wallet id', function () {
    $c = $this->client;

    $unknown = $c->debitWallet(WalletRef::fromWalletId('wlt_nope'), 1_000, new App\Waas\Data\DebitDestination(Kit::DEST), 'k:unknown', 'n');
    expect($unknown->failureReason)->toBe(DebitFailureReason::InvalidAccount);

    $account = Kit::create($c);
    $ok = $c->debitWallet(WalletRef::fromWalletId($account->walletId), 1_000, new App\Waas\Data\DebitDestination(Kit::DEST), 'k:real', 'n');
    expect($ok->state)->toBe(DebitState::Succeeded)
        ->and($c->getBalance($account->nuban))->toBe(WAAS_TEST_START_KOBO - 1_000);
});

it('fails with InsufficientFunds when the amount exceeds the computed balance', function () {
    $r = Kit::debit($this->client, WAAS_TEST_START_KOBO + 1);

    expect($r->failureReason)->toBe(DebitFailureReason::InsufficientFunds);
    expect($this->client->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO);
});

it('rejects amount <= 0 and stores nothing', function (int $amount) {
    Kit::thrown(fn () => Kit::debit($this->client, $amount), InvalidArgumentException::class);

    expect(DB::table('mock_waas_debits')->count())->toBe(0);
})->with([0, -1]);

// ------------------------------------------------------------------ unknown outcomes

it('timeout_applied: throws Unknown, status says Succeeded, replay returns the original, debited once', function () {
    $c = $this->client;
    $nuban = '0000000003';

    $e = Kit::thrown(fn () => Kit::debit($c, 50_000, Kit::KEY, $nuban), DebitOutcomeUnknown::class);
    expect($e->idempotencyKey)->toBe(Kit::KEY)->and($e->cause)->toBe(FaultCause::Timeout);

    $status = $c->getDebitStatus(Kit::KEY);
    expect($status->state)->toBe(DebitState::Succeeded)->and($status->amountKobo)->toBe(50_000);

    $replay = Kit::debit($c, 50_000, Kit::KEY, $nuban);
    expect($replay->state)->toBe(DebitState::Succeeded)
        ->and($replay->providerReference)->toBe($status->providerReference)
        ->and($c->getBalance($nuban))->toBe(WAAS_TEST_START_KOBO - 50_000);
});

it('timeout_not_applied: throws Unknown, status says NotFound, retry with the same key proceeds', function () {
    $c = $this->client;
    $nuban = '0000000004';

    $e = Kit::thrown(fn () => Kit::debit($c, 50_000, Kit::KEY, $nuban), DebitOutcomeUnknown::class);
    expect($e->idempotencyKey)->toBe(Kit::KEY);

    expect($c->getDebitStatus(Kit::KEY)->state)->toBe(DebitState::NotFound)
        ->and($c->getBalance($nuban))->toBe(WAAS_TEST_START_KOBO);

    $retry = Kit::debit($c, 50_000, Kit::KEY, $nuban);
    expect($retry->state)->toBe(DebitState::Succeeded)
        ->and($c->getDebitStatus(Kit::KEY)->state)->toBe(DebitState::Succeeded)
        ->and($c->getBalance($nuban))->toBe(WAAS_TEST_START_KOBO - 50_000);
});

it('reconcile-then-retry end to end: only a NotFound lookup permits the retry', function (string $nuban, bool $moved) {
    $c = $this->client;
    $key = IdempotencyKeys::forSweep('m-1', '2026-11-08');

    // The caller's loop, as the sweep executor should write it.
    try {
        $result = Kit::debit($c, 50_000, $key, $nuban);
    } catch (DebitOutcomeUnknown $e) {
        $status = $c->getDebitStatus($e->idempotencyKey);

        $result = match ($status->state) {
            DebitState::Succeeded, DebitState::Failed => $status,                 // settled: record it, do NOT retry
            DebitState::NotFound => Kit::debit($c, 50_000, $key, $nuban),         // proven not applied: same key is safe
            DebitState::Pending => throw new LogicException('would wait and re-check'),
        };
    }

    expect($result->state)->toBe(DebitState::Succeeded)
        ->and($c->getBalance($nuban))->toBe(WAAS_TEST_START_KOBO - 50_000) // exactly once either way
        ->and(DB::table('mock_waas_debits')->where('idempotency_key', $key)->count())->toBe(1);
})->with([
    'applied, response lost' => ['0000000003', true],
    'not applied, response lost' => ['0000000004', false],
]);

// ------------------------------------------------------------------ reversals

it('reverses a successful debit, restores the balance, and replays the reversal key', function () {
    $c = $this->client;
    $rev = IdempotencyKeys::forReversal(Kit::KEY);
    Kit::debit($c, 100_000);

    $r = $c->reverseDebit(Kit::KEY, $rev, 'customer dispute');

    expect($r->state)->toBe(DebitState::Succeeded)->and($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO);

    $again = $c->reverseDebit(Kit::KEY, $rev, 'customer dispute');
    expect($again->providerReference)->toBe($r->providerReference)
        ->and($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO)
        ->and($c->getDebitStatus($rev)->state)->toBe(DebitState::Succeeded);
});

it('reversal fails with OriginalNotFound for an unknown key or a debit that failed', function () {
    $c = $this->client;
    Kit::debit($c, WAAS_TEST_START_KOBO + 1, 'k:failed'); // Failed: insufficient

    $unknown = $c->reverseDebit('k:never-existed', 'rev:1', 'x');
    $ofFailed = $c->reverseDebit('k:failed', 'rev:2', 'x');

    expect($unknown->failureReason)->toBe(DebitFailureReason::OriginalNotFound)
        ->and($ofFailed->failureReason)->toBe(DebitFailureReason::OriginalNotFound);
});

it('a second reversal under a new key fails with AlreadyReversed and moves nothing', function () {
    $c = $this->client;
    Kit::debit($c, 100_000);
    $c->reverseDebit(Kit::KEY, IdempotencyKeys::forReversal(Kit::KEY), 'x');

    $second = $c->reverseDebit(Kit::KEY, IdempotencyKeys::forReversal(Kit::KEY, 2), 'x');

    expect($second->state)->toBe(DebitState::Failed)
        ->and($second->failureReason)->toBe(DebitFailureReason::AlreadyReversed)
        ->and($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO);
});

it('reversal fails with WindowExpired after 24 hours', function () {
    $c = $this->client;
    Kit::debit($c, 100_000);
    DB::table('mock_waas_debits')->where('idempotency_key', Kit::KEY)->update([
        'created_at' => CarbonImmutable::now('UTC')->subHours(25)->format('Y-m-d\TH:i:sP'),
    ]);

    $r = $c->reverseDebit(Kit::KEY, IdempotencyKeys::forReversal(Kit::KEY), 'x');

    expect($r->failureReason)->toBe(DebitFailureReason::WindowExpired)
        ->and($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO - 100_000);
});

it('a reversal key reused for a different original throws', function () {
    $c = $this->client;
    Kit::debit($c, 100_000);
    $c->reverseDebit(Kit::KEY, 'rev:shared', 'x');

    Kit::thrown(fn () => $c->reverseDebit('k:other', 'rev:shared', 'x'), InvalidArgumentException::class);
});

// ------------------------------------------------------------------ balance

it('balance = start + mock_rail_transactions - successful debits + successful reversals', function () {
    $c = $this->client;

    expect($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO);

    Kit::inflow(Kit::SRC_NUBAN, 250_000);
    Kit::inflow(Kit::OTHER_NUBAN, 999_999); // other accounts don't count
    expect($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO + 250_000);

    Kit::debit($c, 100_000);
    Kit::debit($c, WAAS_TEST_START_KOBO * 10, 'k:too-big'); // Failed debits don't count
    expect($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO + 250_000 - 100_000);

    $c->reverseDebit(Kit::KEY, 'rev:1', 'x');
    expect($c->getBalance(Kit::SRC_NUBAN))->toBe(WAAS_TEST_START_KOBO + 250_000);
});

it('the low-balance NUBAN starts at ₦1,200 so a ₦500 sweep falls under a ₦1,000 buffer', function () {
    $c = $this->client;
    $nuban = '0000000005';

    expect($c->getBalance($nuban))->toBe(120_000);

    Kit::debit($c, 50_000, Kit::KEY, $nuban);
    expect($c->getBalance($nuban))->toBe(70_000)->and(70_000)->toBeLessThan(100_000);

    expect(Kit::debit($c, 80_000, 'k:2', $nuban)->failureReason)->toBe(DebitFailureReason::InsufficientFunds);

    Kit::inflow($nuban, 500_000);
    expect($c->getBalance($nuban))->toBe(570_000);
});

it('getBalance rejects the invalid-account trigger and malformed NUBANs', function (string $nuban) {
    Kit::thrown(fn () => $this->client->getBalance($nuban), WaasAccountNotFound::class);
})->with(['0000000002', '123', 'abcdefghij']);

// ------------------------------------------------------------------ createAccount

it('creates an account named "{name} (Kobo Kobo)" with an 08-prefixed NUBAN and stores only hashes', function () {
    $account = Kit::create($this->client);

    expect($account->accountName)->toBe('Blessing Okon (Kobo Kobo)')
        ->and($account->nuban)->toMatch('/^08\d{8}$/')
        ->and($account->walletId)->toStartWith('wlt_');

    $hasher = app(IdentityHasher::class);
    $row = DB::table('mock_waas_accounts')->first();

    expect($row->bvn_hash)->toBe($hasher->hashBvn(Kit::BVN))
        ->and($row->nin_hash)->toBe($hasher->hashNin(Kit::NIN));

    $dump = json_encode(DB::table('mock_waas_accounts')->get()).json_encode(DB::table('mock_waas_debits')->get());
    expect($dump)->not->toContain(Kit::BVN)->and($dump)->not->toContain(Kit::NIN);
});

it('rejects a second create with the same BVN (by hash), whatever the other fields', function () {
    $c = $this->client;
    Kit::create($c);

    $e = Kit::thrown(fn () => Kit::create($c, Kit::BVN, '+2348099999999', null, 'Someone Else'), AccountCreationRejected::class);

    expect($e->reason)->toBe(AccountCreationFailure::DuplicateBvn)
        ->and(DB::table('mock_waas_accounts')->count())->toBe(1);
});

it('a different BVN creates a separate account', function () {
    $c = $this->client;

    $a = Kit::create($c);
    $b = Kit::create($c, Kit::BVN_2);

    expect($a->nuban)->not->toBe($b->nuban)->and(DB::table('mock_waas_accounts')->count())->toBe(2);
});

it('a malformed BVN is InvalidIdentity with no chained exception', function () {
    $e = Kit::thrown(fn () => Kit::create($this->client, '12345'), AccountCreationRejected::class);

    expect($e->reason)->toBe(AccountCreationFailure::InvalidIdentity);
});

it('the outage phone throws a retryable WaasUnavailable and stores nothing', function () {
    $e = Kit::thrown(fn () => Kit::create($this->client, Kit::BVN, '+2348000000099'), WaasUnavailable::class);

    expect($e->operation)->toBe('createAccount')
        ->and(DB::table('mock_waas_accounts')->count())->toBe(0);
});

it('the name-mismatch phone returns the configured different account name', function () {
    $account = Kit::create($this->client, Kit::BVN, '+2348000000098');

    expect($account->accountName)->toBe(config('waas.mock.mismatch_account_name'))
        ->and($account->accountName)->not->toContain('Blessing Okon');
});

it('the same reference returns the same account, even though the BVN now exists', function () {
    $c = $this->client;
    $ref = 'a1b2c3d4-0000-4000-8000-000000000001';

    $first = Kit::create($c, reference: $ref);
    $second = Kit::create($c, reference: $ref);

    expect($second->nuban)->toBe($first->nuban)
        ->and($second->walletId)->toBe($first->walletId)
        ->and(DB::table('mock_waas_accounts')->count())->toBe(1);
});

it('the same reference with a different BVN throws', function () {
    $c = $this->client;
    $ref = 'a1b2c3d4-0000-4000-8000-000000000002';
    Kit::create($c, reference: $ref);

    Kit::thrown(fn () => Kit::create($c, Kit::BVN_2, reference: $ref), InvalidArgumentException::class);
});

it('different references with the same BVN is still a duplicate BVN', function () {
    $c = $this->client;
    Kit::create($c, reference: 'ref-one');

    $e = Kit::thrown(fn () => Kit::create($c, reference: 'ref-two'), AccountCreationRejected::class);

    expect($e->reason)->toBe(AccountCreationFailure::DuplicateBvn);
});

it('rejects malformed references without echoing them', function (string $bad) {
    Kit::thrown(fn () => Kit::create($this->client, reference: $bad), InvalidArgumentException::class, [$bad]);

    expect(DB::table('mock_waas_accounts')->count())->toBe(0);
})->with(['empty' => [''], 'spaces' => ['has space'], 'bvn-like' => ['55555555555'], 'too long' => [str_repeat('a', 65)]]);