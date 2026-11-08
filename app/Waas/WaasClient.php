<?php

declare(strict_types=1);

namespace App\Waas;

use App\Waas\Data\DebitDestination;
use App\Waas\Data\DebitResult;
use App\Waas\Data\WaasAccount;
use App\Waas\Data\WalletRef;
use App\Waas\Exceptions\AccountCreationRejected;
use App\Waas\Exceptions\DebitOutcomeUnknown;
use App\Waas\Exceptions\WaasAccountNotFound;
use App\Waas\Exceptions\WaasCapabilityUnsupported;
use App\Waas\Exceptions\WaasUnavailable;
use DateTimeImmutable;
use InvalidArgumentException;

interface WaasClient
{
    /**
     * $reference: onboarding generates a UUID, persists it, and passes the SAME value on every retry
     * of the same signup. Never derive it from the BVN/NIN or their hash. The mock returns the same
     * account for the same reference; the real provider's behaviour is TODO(unverified).
     *
     * @throws WaasUnavailable           outage/timeout/5xx/malformed response (retryable)
     * @throws AccountCreationRejected   business rejection (duplicate BVN, invalid identity, ...)
     * @throws InvalidArgumentException  malformed $reference, or reference reused with a different BVN
     */

    // createAccount: TODO(unverified): is NUBAN issuance synchronous? KYC tier limits? Are NIN and DOB mandatory?
    // debitWallet: TODO(unverified): provider behaviour when a key is reused with different parameters (the mock throws).
    // TODO(unverified): is the debit response final? If WaaS answers "pending" and settles later (webhook),
    // every debit is Unknown until reconciled, and the sweep flow needs the webhook path (B1).
    // reverseDebit: TODO(unverified): can funds be reversed once they reach Access ARM (non-custodial design)? Who initiates?
    // TODO(unverified): does getDebitStatus() accept a reversal key?
    
    public function createAccount(
        string $fullName,
        string $phone,
        #[\SensitiveParameter] string $bvn,
        #[\SensitiveParameter] ?string $nin,
        DateTimeImmutable $dateOfBirth,
        ?string $reference = null,
    ): WaasAccount;

    /**
     * Returns ONLY Succeeded or Failed. Anything where money may have moved
     * (timeout, connection drop, 5xx, malformed body, "pending") throws.
     * Same idempotencyKey twice returns the original result; never debits twice.
     *
     * @throws InvalidArgumentException  amountKobo <= 0
     * @throws DebitOutcomeUnknown       caller MUST reconcile via getDebitStatus() before any retry
     */
    public function debitWallet(
        WalletRef $source,
        int $amountKobo,
        DebitDestination $destination,
        string $idempotencyKey,
        string $narration,
    ): DebitResult;

    /**
     * Reverse a previously SUCCEEDED debit (24h reversal window). Returns ONLY
     * Succeeded or Failed (reasons incl. OriginalNotFound, AlreadyReversed, WindowExpired).
     * Same reversalKey twice returns the original reversal result; never reverses twice.
     * $reason is a short code/phrase: no PII.
     *
     * @throws DebitOutcomeUnknown         carries $reversalKey; reconcile via getDebitStatus($reversalKey) before retry
     * @throws WaasCapabilityUnsupported   provider can't reverse
     */
    public function reverseDebit(string $originalIdempotencyKey, string $reversalKey, string $reason): DebitResult;

    /**
     * Balance in kobo.
     *
     * @throws WaasUnavailable
     * @throws WaasAccountNotFound
     * @throws WaasCapabilityUnsupported  provider offers no balance endpoint
     */
    public function getBalance(string $nuban): int;

    /**
     * Look up a debit OR reversal by the key it was sent with. State is Succeeded, Failed,
     * Pending (don't retry yet) or NotFound (provider has no record).
     *
     * @throws WaasUnavailable  lookup itself failed; outcome still unknown
     */
    public function getDebitStatus(string $idempotencyKey): DebitResult;
}