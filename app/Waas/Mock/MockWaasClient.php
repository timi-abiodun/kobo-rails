<?php

declare(strict_types=1);

namespace App\Waas\Mock;

use App\Identity\IdentityHasher;
use App\Waas\Data\DebitDestination;
use App\Waas\Data\DebitResult;
use App\Waas\Data\WaasAccount;
use App\Waas\Data\WalletRef;
use App\Waas\Enums\AccountCreationFailure;
use App\Waas\Enums\DebitFailureReason;
use App\Waas\Enums\FaultCause;
use App\Waas\Exceptions\AccountCreationRejected;
use App\Waas\Exceptions\DebitOutcomeUnknown;
use App\Waas\Exceptions\WaasAccountNotFound;
use App\Waas\Exceptions\WaasUnavailable;
use App\Waas\Support\Amount;
use App\Waas\Support\Mask;
use App\Waas\WaasClient;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Waas\Support\Reference;

/**
 * State lives in mock_waas_accounts / mock_waas_debits so it survives across requests.
 * Balance = starting + SUM(mock_rail_transactions) - successful debits + successful reversals.
 */
final class MockWaasClient implements WaasClient
{
    private const MAX_NUBAN_ATTEMPTS = 3;

    /** @param array<string, mixed> $config the `waas.mock` config block */
    public function __construct(
        private readonly IdentityHasher $hasher,
        private readonly array $config,
    ) {}

    // ---------------------------------------------------------------- createAccount

    public function createAccount(
        string $fullName,
        string $phone,
        #[\SensitiveParameter] string $bvn,
        #[\SensitiveParameter] ?string $nin,
        DateTimeImmutable $dateOfBirth,
        ?string $reference = null,
    ): WaasAccount {
        Reference::assertValid($reference);
        $triggers = $this->config['triggers']['create'];

        if ($phone === ($triggers['outage_phone'] ?? null)) {
            throw new WaasUnavailable('createAccount', FaultCause::ConnectionFailed);
        }

        try {
            $bvnHash = $this->hasher->hashBvn($bvn);
            $ninHash = $nin !== null ? $this->hasher->hashNin($nin) : null;
        } catch (InvalidArgumentException) {
            // Deliberately not chained: the original may describe the input.
            throw new AccountCreationRejected(AccountCreationFailure::InvalidIdentity);
        }

        if ($reference !== null && ($existing = $this->accountByReference($reference)) !== null) {
            return $this->replayAccount($existing, $bvnHash);
        }

        if ($this->bvnHashExists($bvnHash)) {
            throw new AccountCreationRejected(AccountCreationFailure::DuplicateBvn);
        }

        $accountName = $phone === ($triggers['name_mismatch_phone'] ?? null)
            ? (string) $this->config['mismatch_account_name']
            : trim($fullName).' (Kobo Kobo)';

        for ($attempt = 1; ; $attempt++) {
            $nuban = '08'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $walletId = 'wlt_'.Str::lower((string) Str::ulid());
            $now = $this->ts(CarbonImmutable::now('UTC'));

            try {
                // Savepoint: a unique violation must not poison an outer (test) transaction on Postgres.
                DB::transaction(fn () => DB::table('mock_waas_accounts')->insert([
                    'nuban' => $nuban,
                    'wallet_id' => $walletId,
                    'account_name' => $accountName,
                    'bvn_hash' => $bvnHash,
                    'nin_hash' => $ninHash,
                    'reference' => $reference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                return new WaasAccount($nuban, $walletId, $accountName);
            } catch (UniqueConstraintViolationException) {
                if ($reference !== null && ($existing = $this->accountByReference($reference)) !== null) {
                    return $this->replayAccount($existing, $bvnHash); // lost a race on the same reference
                }
                if ($this->bvnHashExists($bvnHash)) {
                    throw new AccountCreationRejected(AccountCreationFailure::DuplicateBvn);
                }
                if ($attempt >= self::MAX_NUBAN_ATTEMPTS) {
                    throw new WaasUnavailable('createAccount', FaultCause::ServerError);
                }
            }
        }
    }

    private function accountByReference(string $reference): ?object
    {
        return DB::table('mock_waas_accounts')->where('reference', $reference)->first();
    }

    /** Same reference + same identity => original account. Same reference + different identity => loud error. */
    private function replayAccount(object $row, string $bvnHash): WaasAccount
    {
        if (! hash_equals((string) $row->bvn_hash, $bvnHash)) {
            throw new InvalidArgumentException('Account reference reused with different parameters.');
        }

        return new WaasAccount($row->nuban, $row->wallet_id, $row->account_name);
    }

    // ---------------------------------------------------------------- debitWallet

    public function debitWallet(
        WalletRef $source,
        int $amountKobo,
        DebitDestination $destination,
        string $idempotencyKey,
        string $narration,
    ): DebitResult {
        Amount::assertPositiveKobo($amountKobo);

        $fingerprint = hash('sha256', implode('|', [
            $source->nuban ?? '', $source->walletId ?? '', $amountKobo,
            $destination->accountNumber, $destination->bankCode ?? '',
        ]));

        $allowTimeouts = true;
        $existing = $this->row($idempotencyKey);

        if ($existing !== null) {
            if ($existing->state === 'lost') {
                // Earlier attempt timed out and never applied. The "outage" has cleared: this retry proceeds normally.
                DB::table('mock_waas_debits')->where('id', $existing->id)->delete();
                $allowTimeouts = false;
            } else {
                return $this->replay($existing, 'debit', $fingerprint);
            }
        }

        $nuban = $this->resolveNuban($source);
        $t = $this->config['triggers']['debit'];
        $base = [
            'idempotency_key' => $idempotencyKey,
            'kind' => 'debit',
            'source_nuban' => $nuban,
            'amount_kobo' => $amountKobo,
            'destination_masked' => Mask::account($destination->accountNumber),
            'request_fingerprint' => $fingerprint,
        ];

        if ($nuban === null || $nuban === $t['invalid_account']) {
            return $this->storeFailed($base, DebitFailureReason::InvalidAccount);
        }

        if ($nuban === $t['insufficient_funds']) {
            return $this->storeFailed($base, DebitFailureReason::InsufficientFunds);
        }

        if ($allowTimeouts && $nuban === $t['timeout_not_applied']) {
            $this->insertRow($base + ['state' => 'lost']);
            throw new DebitOutcomeUnknown($idempotencyKey, FaultCause::Timeout);
        }

        if ($allowTimeouts && $nuban === $t['timeout_applied']) {
            // Money DID move (balance check skipped on purpose), but the response is lost.
            $this->storeSucceeded($base);
            throw new DebitOutcomeUnknown($idempotencyKey, FaultCause::Timeout);
        }

        if ($amountKobo > $this->computeBalance($nuban)) {
            return $this->storeFailed($base, DebitFailureReason::InsufficientFunds);
        }

        return $this->storeSucceeded($base);
    }

    // ---------------------------------------------------------------- reverseDebit

    public function reverseDebit(string $originalIdempotencyKey, string $reversalKey, string $reason): DebitResult
    {
        $fingerprint = hash('sha256', $originalIdempotencyKey);

        $existing = $this->row($reversalKey);
        if ($existing !== null) {
            return $this->replay($existing, 'reversal', $fingerprint);
        }

        $original = $this->row($originalIdempotencyKey);
        $base = [
            'idempotency_key' => $reversalKey,
            'kind' => 'reversal',
            'source_nuban' => $original?->source_nuban,
            'amount_kobo' => $original?->amount_kobo,
            'request_fingerprint' => $fingerprint,
            'original_key' => $originalIdempotencyKey,
        ];

        if ($original === null || $original->kind !== 'debit' || $original->state !== 'succeeded') {
            return $this->storeFailed($base, DebitFailureReason::OriginalNotFound);
        }

        $alreadyReversed = DB::table('mock_waas_debits')
            ->where('kind', 'reversal')
            ->where('original_key', $originalIdempotencyKey)
            ->where('state', 'succeeded')
            ->exists();

        if ($alreadyReversed) {
            return $this->storeFailed($base, DebitFailureReason::AlreadyReversed);
        }

        $windowHours = (int) ($this->config['reversal_window_hours'] ?? 24);
        if (CarbonImmutable::parse((string) $original->created_at)->utc()->addHours($windowHours)->isPast()) {
            return $this->storeFailed($base, DebitFailureReason::WindowExpired);
        }

        return $this->storeSucceeded($base);
    }

    // ---------------------------------------------------------------- getBalance / getDebitStatus

    public function getBalance(string $nuban): int
    {
        if (! preg_match('/^\d{10}$/', $nuban) || $nuban === $this->config['triggers']['debit']['invalid_account']) {
            throw new WaasAccountNotFound($nuban);
        }

        return $this->computeBalance($nuban);
    }

    public function getDebitStatus(string $idempotencyKey): DebitResult
    {
        $row = $this->row($idempotencyKey);

        return $row === null ? DebitResult::notFound($idempotencyKey) : $this->toResult($row);
    }

    // ---------------------------------------------------------------- internals

    private function computeBalance(string $nuban): int
    {
        $start = $nuban === $this->config['triggers']['debit']['low_balance']
            ? (int) $this->config['low_balance_starting_kobo']
            : (int) $this->config['starting_balance_kobo'];

        $inflows = (int) DB::table('mock_rail_transactions')->where('nuban', $nuban)->sum('amount_kobo');

        $sumOf = fn (string $kind): int => (int) DB::table('mock_waas_debits')
            ->where('source_nuban', $nuban)->where('kind', $kind)->where('state', 'succeeded')
            ->sum('amount_kobo');

        return $start + $inflows - $sumOf('debit') + $sumOf('reversal');
    }

    private function resolveNuban(WalletRef $source): ?string
    {
        if ($source->nuban !== null) {
            return $source->nuban;
        }

        $nuban = DB::table('mock_waas_accounts')->where('wallet_id', $source->walletId)->value('nuban');

        return is_string($nuban) ? $nuban : null;
    }

    private function bvnHashExists(string $bvnHash): bool
    {
        return DB::table('mock_waas_accounts')->where('bvn_hash', $bvnHash)->exists();
    }

    private function row(string $key): ?object
    {
        return DB::table('mock_waas_debits')->where('idempotency_key', $key)->first();
    }

    /** Same key + same request => original result. Same key + different request => loud programming error. */
    // TODO(unverified): mock-only behaviour (same key + different params throws; a lost debit reads as NotFound). Align with the real provider's docs.
    private function replay(object $row, string $kind, string $fingerprint): DebitResult
    {
        if ($row->kind !== $kind || $row->request_fingerprint !== $fingerprint) {
            throw new InvalidArgumentException('Idempotency key reused with different parameters.');
        }

        return $this->toResult($row);
    }

    /** @param array<string, mixed> $base */
    private function storeSucceeded(array $base): DebitResult
    {
        $ref = 'MOCK-'.strtoupper(substr(hash('sha256', $base['idempotency_key']), 0, 12));

        return $this->toResult($this->insertRow($base + ['state' => 'succeeded', 'provider_reference' => $ref]));
    }

    /** @param array<string, mixed> $base */
    private function storeFailed(array $base, DebitFailureReason $reason): DebitResult
    {
        return $this->toResult($this->insertRow($base + ['state' => 'failed', 'failure_reason' => $reason->value]));
    }

    /**
     * Inserts, or on a unique-key race returns the winning row. Always returns the row that is stored.
     *
     * @param array<string, mixed> $attrs
     */
    private function insertRow(array $attrs): object
    {
        $now = $this->ts(CarbonImmutable::now('UTC'));

        try {
            DB::transaction(fn () => DB::table('mock_waas_debits')->insert($attrs + [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            // concurrent request with the same key won
        }

        return $this->row($attrs['idempotency_key'])
            ?? throw new WaasUnavailable('mock-store', FaultCause::ServerError);
    }

    private function toResult(object $row): DebitResult
    {
        $at = CarbonImmutable::parse((string) $row->created_at)->utc();

        return match ($row->state) {
            'succeeded' => DebitResult::succeeded($row->idempotency_key, (int) $row->amount_kobo, $row->provider_reference, $at),
            'failed' => DebitResult::failed(
                $row->idempotency_key,
                DebitFailureReason::from($row->failure_reason),
                $row->amount_kobo !== null ? (int) $row->amount_kobo : null,
            ),
            default => DebitResult::notFound($row->idempotency_key), // 'lost': provider has no record
        };
    }

    private function ts(CarbonImmutable $d): string
    {
        return $d->utc()->format('Y-m-d\TH:i:sP'); // explicit +00:00, independent of the DB session timezone
    }
}