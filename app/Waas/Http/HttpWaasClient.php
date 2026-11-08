<?php

declare(strict_types=1);

namespace App\Waas\Http;

use App\Waas\Data\DebitDestination;
use App\Waas\Data\DebitResult;
use App\Waas\Data\WaasAccount;
use App\Waas\Data\WalletRef;
use App\Waas\Enums\AccountCreationFailure;
use App\Waas\Enums\DebitFailureReason;
use App\Waas\Enums\DebitState;
use App\Waas\Enums\FaultCause;
use App\Waas\Exceptions\AccountCreationRejected;
use App\Waas\Exceptions\DebitOutcomeUnknown;
use App\Waas\Exceptions\WaasAccountNotFound;
use App\Waas\Exceptions\WaasCapabilityUnsupported;
use App\Waas\Exceptions\WaasMisconfigured;
use App\Waas\Exceptions\WaasUnavailable;
use App\Waas\Support\Amount;
use App\Waas\Support\WireMoney;
use App\Waas\WaasClient;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use App\Waas\Support\Reference;

/**
 * Rules this class lives by:
 *  - NO ->retry() anywhere, and no ->throw() (RequestException carries URL/body). Retrying is the caller's job.
 *  - Never log, and never put a URL, request body, response body, BVN/NIN or full account in a message.
 *  - Transport exceptions are caught and replaced; they are never chained as "previous".
 *  - Debit/reversal: only Succeeded or Failed are returned; anything ambiguous throws DebitOutcomeUnknown.
 *    "Failed" requires a parseable 4xx/2xx body that clearly says nothing moved.
 */
final class HttpWaasClient implements WaasClient
{
    /**
     * @param array<string, mixed> $timeouts     connect|read|debit (seconds)
     * @param array<string, mixed> $capabilities balance|reversal => bool
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly array $timeouts = [],
        private readonly array $capabilities = [],
    ) {}

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '***'];
    }

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

        $body = array_filter([
            WireFormat::F_FULL_NAME => $fullName,
            WireFormat::F_PHONE => $phone,
            WireFormat::F_BVN => $bvn,
            WireFormat::F_NIN => $nin,
            WireFormat::F_DOB => $dateOfBirth->format(WireFormat::DOB_FORMAT),
            WireFormat::F_ACCOUNT_REFERENCE => $reference,
        ], fn ($v) => $v !== null);

        $r = $this->dispatch(
            'POST',
            WireFormat::PATH_CREATE_ACCOUNT,
            $body,
            $this->readTimeout(),
            $reference !== null ? [WireFormat::IDEMPOTENCY_HEADER => $reference] : [],
        );

        if ($r instanceof FaultCause) {
            throw new WaasUnavailable('createAccount', $r);
        }

        $this->guardAuth($r); // 401/403
        $status = $r->status();

        // Our request is wrong (bad path/method/content type): a deploy/config bug, never a business outcome.
        if (in_array($status, [404, 405, 415], true)) {
            throw new WaasMisconfigured("WaaS createAccount request is misconfigured (HTTP {$status}); check WAAS_BASE_URL and WireFormat.");
        }

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new WaasUnavailable('createAccount', FaultCause::ServerError);
        }

        $json = $r->json();

        if ($status >= 200 && $status < 300) {
            $nuban = data_get($json, WireFormat::R_NUBAN);
            $wallet = data_get($json, WireFormat::R_WALLET_ID);
            $name = data_get($json, WireFormat::R_ACCOUNT_NAME);

            if (! is_string($nuban) || ! preg_match('/^\d{10}$/', $nuban)
                || ! is_string($wallet) || $wallet === ''
                || ! is_string($name) || trim($name) === '') {
                throw new WaasUnavailable('createAccount', FaultCause::MalformedResponse);
            }

            return new WaasAccount($nuban, $wallet, trim($name));
        }

        if (in_array($status, [400, 409, 422], true)) {
            $code = self::lower(data_get($json, WireFormat::R_FAILURE_CODE));

            throw new AccountCreationRejected(WireFormat::CREATE_FAILURE_CODES[$code] ?? AccountCreationFailure::Rejected);
        }

        // 3xx, other 4xx: not an outcome we recognise.
        throw new WaasUnavailable('createAccount', FaultCause::MalformedResponse);
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

        $body = array_filter([
            $source->nuban !== null ? WireFormat::F_SOURCE_NUBAN : WireFormat::F_SOURCE_WALLET => $source->nuban ?? $source->walletId,
            WireFormat::F_DEST_ACCOUNT => $destination->accountNumber,
            WireFormat::F_DEST_BANK => $destination->bankCode,
            WireFormat::F_DEST_NAME => $destination->accountName,
            WireFormat::F_AMOUNT => WireMoney::toWire($amountKobo),
            WireFormat::F_NARRATION => $narration,
            WireFormat::F_REFERENCE => $idempotencyKey,
        ], fn ($v) => $v !== null);

        $r = $this->dispatch(
            'POST',
            WireFormat::PATH_DEBIT,
            $body,
            (int) ($this->timeouts['debit'] ?? 15),
            [WireFormat::IDEMPOTENCY_HEADER => $idempotencyKey],
        );

        if ($r instanceof FaultCause) {
            // Timeout or dropped connection: the provider may have processed it. Never "Failed".
            throw new DebitOutcomeUnknown($idempotencyKey, $r);
        }

        return $this->interpretMovement($r, $idempotencyKey, $amountKobo);
    }

    // ---------------------------------------------------------------- reverseDebit

    public function reverseDebit(string $originalIdempotencyKey, string $reversalKey, string $reason): DebitResult
    {
        if (! ($this->capabilities['reversal'] ?? false)) {
            throw new WaasCapabilityUnsupported('reverseDebit'); // TODO(unverified): does WaaS offer reversal?
        }

        $r = $this->dispatch(
            'POST',
            WireFormat::PATH_REVERSAL,
            [
                WireFormat::F_ORIGINAL_REF => $originalIdempotencyKey,
                WireFormat::F_REFERENCE => $reversalKey,
                WireFormat::F_REASON => $reason,
            ],
            (int) ($this->timeouts['debit'] ?? 15),
            [WireFormat::IDEMPOTENCY_HEADER => $reversalKey],
        );

        if ($r instanceof FaultCause) {
            throw new DebitOutcomeUnknown($reversalKey, $r);
        }

        return $this->interpretMovement($r, $reversalKey, null);
    }

    // ---------------------------------------------------------------- getBalance

    public function getBalance(string $nuban): int
    {
        if (! ($this->capabilities['balance'] ?? false)) {
            throw new WaasCapabilityUnsupported('getBalance'); // TODO(unverified): does WaaS expose balance?
        }

        if (! preg_match('/^\d{10}$/', $nuban)) {
            throw new WaasAccountNotFound($nuban); // also keeps junk out of the URL path
        }

        $r = $this->dispatch('GET', str_replace('{nuban}', $nuban, WireFormat::PATH_BALANCE), null, $this->readTimeout());

        if ($r instanceof FaultCause) {
            throw new WaasUnavailable('getBalance', $r);
        }

        $this->guardAuth($r);
        $status = $r->status();

        if ($status === 404) {
            throw new WaasAccountNotFound($nuban);
        }

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new WaasUnavailable('getBalance', FaultCause::ServerError);
        }

        if ($status >= 200 && $status < 300) {
            $kobo = WireMoney::fromWire(data_get($r->json(), WireFormat::R_BALANCE));

            return $kobo ?? throw new WaasUnavailable('getBalance', FaultCause::MalformedResponse);
        }

        throw new WaasUnavailable('getBalance', FaultCause::MalformedResponse);
    }

    // ---------------------------------------------------------------- getDebitStatus
    // TODO(unverified): CRITICAL. Does WaaS offer a status lookup by OUR reference/idempotency key?
    //   If not, an Unknown debit can only be reconciled via a statement/transactions endpoint or by hand.
    // TODO(unverified): can a debit be in flight yet "not found" (eventual consistency)? If so, NotFound alone isn't a retry licence.
    public function getDebitStatus(string $idempotencyKey): DebitResult
    {
        $path = str_replace('{key}', rawurlencode($idempotencyKey), WireFormat::PATH_DEBIT_STATUS);
        $r = $this->dispatch('GET', $path, null, $this->readTimeout());

        if ($r instanceof FaultCause) {
            throw new WaasUnavailable('getDebitStatus', $r); // lookup failed: outcome is STILL unknown
        }

        $this->guardAuth($r);
        $status = $r->status();

        if ($status === 404) {
            return DebitResult::notFound($idempotencyKey); // TODO(unverified): is 404 how WaaS says "no such reference"?
        }

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new WaasUnavailable('getDebitStatus', FaultCause::ServerError);
        }

        $json = $r->json();

        if ($status >= 200 && $status < 300 && is_array($json)) {
            $state = self::lower(data_get($json, WireFormat::R_STATUS));
            $amount = WireMoney::fromWire(data_get($json, WireFormat::R_AMOUNT));

            if (in_array($state, WireFormat::STATUS_SUCCESS, true)) {
                return $this->succeeded($idempotencyKey, $amount, $json);
            }
            if (in_array($state, WireFormat::STATUS_PENDING, true)) {
                return DebitResult::pending($idempotencyKey);
            }
            if (in_array($state, WireFormat::STATUS_FAILED, true)) {
                return DebitResult::failed($idempotencyKey, $this->failureReason($json) ?? DebitFailureReason::Rejected, $amount);
            }
        }

        throw new WaasUnavailable('getDebitStatus', FaultCause::MalformedResponse);
    }

    // ---------------------------------------------------------------- internals

    /** Transport failures come back as a FaultCause, never as an exception (so nothing can leak a URL). */
    private function dispatch(string $method, string $path, ?array $body, int $timeout, array $headers = []): Response|FaultCause
    {
        try {
            $req = $this->pending($timeout)->withHeaders($headers);

            return $method === 'GET' ? $req->get($path) : $req->post($path, $body ?? []);
        } catch (ConnectionException $e) {
            // Message read locally for classification only; it is never stored, logged or rethrown.
            return str_contains($e->getMessage(), 'cURL error 28') ? FaultCause::Timeout : FaultCause::ConnectionFailed;
        } catch (GuzzleException) {
            return FaultCause::ConnectionFailed;
        }
    }

    private function pending(int $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey) // TODO(unverified): auth scheme (bearer? custom header? OAuth?)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) ($this->timeouts['connect'] ?? 3))
            ->timeout($timeout);
        // Deliberately NO ->retry().
    }

    private function readTimeout(): int
    {
        return (int) ($this->timeouts['read'] ?? 10);
    }

    /** Auth failure is our config, not an outcome: fail loudly instead of returning a result. */
    private function guardAuth(Response $r): void
    {
        if (in_array($r->status(), [401, 403], true)) {
            throw new WaasMisconfigured('WaaS rejected our credentials (HTTP '.$r->status().'); check WAAS_API_KEY.');
        }
    }

    /** Shared by debit + reversal. Returns Succeeded/Failed or throws; never returns Pending/NotFound. */
    private function interpretMovement(Response $r, string $key, ?int $amountKobo): DebitResult
    {
        $this->guardAuth($r);
        $status = $r->status();

        // TODO(unverified): is 409 "in flight" or "duplicate"? Is 429/408 rejected before processing? All kept Unknown to be safe.
        // 5xx, timeout-ish, conflict (maybe in flight), rate limit: we can't prove nothing moved.
        if ($status >= 500 || in_array($status, [408, 409, 429], true)) {
            throw new DebitOutcomeUnknown($key, FaultCause::ServerError);
        }

        $json = $r->json();

        if (! is_array($json)) {
            throw new DebitOutcomeUnknown($key, FaultCause::MalformedResponse);
        }

        $state = self::lower(data_get($json, WireFormat::R_STATUS));
        $reason = $this->failureReason($json);

        if ($status >= 200 && $status < 300) {
            if (in_array($state, WireFormat::STATUS_SUCCESS, true)) {
                return $this->succeeded($key, $amountKobo ?? WireMoney::fromWire(data_get($json, WireFormat::R_AMOUNT)), $json);
            }
            if (in_array($state, WireFormat::STATUS_PENDING, true)) {
                throw new DebitOutcomeUnknown($key, FaultCause::PendingResponse);
            }
            if (in_array($state, WireFormat::STATUS_FAILED, true)) {
                return DebitResult::failed($key, $reason ?? DebitFailureReason::Rejected, $amountKobo);
            }
            throw new DebitOutcomeUnknown($key, FaultCause::MalformedResponse);
        }

        // 4xx: Failed only if the body clearly says so; an unrecognised 4xx stays Unknown.
        if ($status >= 400 && $status < 500 && ($reason !== null || in_array($state, WireFormat::STATUS_FAILED, true))) {
            return DebitResult::failed($key, $reason ?? DebitFailureReason::Rejected, $amountKobo);
        }

        throw new DebitOutcomeUnknown($key, FaultCause::MalformedResponse);
    }

    /** @param array<string, mixed> $json */
    private function succeeded(string $key, ?int $amountKobo, array $json): DebitResult
    {
        $ref = data_get($json, WireFormat::R_PROVIDER_REF);

        return new DebitResult(
            DebitState::Succeeded,
            $key,
            $amountKobo,
            is_string($ref) ? $ref : null,
            null,
            self::at(data_get($json, WireFormat::R_PROCESSED_AT)),
        );
    }

    /** @param array<string, mixed> $json */
    private function failureReason(array $json): ?DebitFailureReason
    {
        return WireFormat::FAILURE_CODES[self::lower(data_get($json, WireFormat::R_FAILURE_CODE))] ?? null;
    }

    private static function lower(mixed $v): string
    {
        return is_string($v) ? strtolower($v) : '';
    }

    private static function at(mixed $v): ?DateTimeImmutable
    {
        if (! is_string($v)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($v)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}