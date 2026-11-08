<?php

declare(strict_types=1);

namespace App\Waas\Http;

use App\Waas\Enums\AccountCreationFailure;
use App\Waas\Enums\DebitFailureReason;

/**
 * TODO(unverified): EVERY path, field name, status string and code in this file is a PLACEHOLDER.
 * Replace each from the Access WaaS docs. The PRD names createAccount / debitWallet / wallet2AccessBank,
 * but the PRD is not the provider's documentation.
 */
final class WireFormat
{
    // --- paths (TODO(unverified)) ---
    public const PATH_CREATE_ACCOUNT = '/v1/waas/account/create';       // from the PRD, unconfirmed
    public const PATH_DEBIT = '/v1/waas/debit';
    public const PATH_REVERSAL = '/v1/waas/debit/reverse';
    public const PATH_BALANCE = '/v1/waas/account/{nuban}/balance';
    public const PATH_DEBIT_STATUS = '/v1/waas/debit/{key}';

    // --- idempotency (TODO(unverified)): header, body reference, or neither is honoured. We send both. ---
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';
    public const F_REFERENCE = 'reference';

    // --- money (TODO(unverified)): 'kobo' = integer minor units, 'naira' = decimal major units ---
    public const AMOUNT_UNIT = 'kobo';

    // --- request fields (TODO(unverified)) ---
    public const F_FULL_NAME = 'fullName';
    public const F_PHONE = 'phoneNumber';
    public const F_BVN = 'bvn';
    public const F_NIN = 'nin';
    public const F_DOB = 'dateOfBirth';
    public const DOB_FORMAT = 'Y-m-d';
    public const F_SOURCE_NUBAN = 'sourceAccountNumber';
    public const F_SOURCE_WALLET = 'sourceWalletId';
    public const F_DEST_ACCOUNT = 'destinationAccountNumber';
    public const F_DEST_BANK = 'destinationBankCode';
    public const F_DEST_NAME = 'destinationAccountName';
    public const F_AMOUNT = 'amount';
    public const F_NARRATION = 'narration';
    public const F_ORIGINAL_REF = 'originalReference';
    public const F_REASON = 'reason';
    public const F_ACCOUNT_REFERENCE = 'reference'; // TODO(unverified): field name, and whether createAccount honours it at all

    // --- response fields, data_get() dot paths (TODO(unverified)) ---
    public const R_NUBAN = 'data.accountNumber';
    public const R_WALLET_ID = 'data.walletId';
    public const R_ACCOUNT_NAME = 'data.accountName';
    public const R_BALANCE = 'data.balance';
    public const R_STATUS = 'data.status';
    public const R_FAILURE_CODE = 'data.failureCode';
    public const R_PROVIDER_REF = 'data.transactionId';
    public const R_PROCESSED_AT = 'data.processedAt';
    public const R_AMOUNT = 'data.amount';

    // --- status values, lowercase (TODO(unverified)) ---
    public const STATUS_SUCCESS = ['success', 'successful', 'completed'];
    public const STATUS_PENDING = ['pending', 'processing', 'queued'];
    public const STATUS_FAILED = ['failed', 'rejected', 'declined'];

    // --- provider failure code (lowercase) => our reason (TODO(unverified)) ---
    public const FAILURE_CODES = [
        'insufficient_funds' => DebitFailureReason::InsufficientFunds,
        'invalid_account' => DebitFailureReason::InvalidAccount,
        'account_not_found' => DebitFailureReason::InvalidAccount,
        'rejected' => DebitFailureReason::Rejected,
        'original_not_found' => DebitFailureReason::OriginalNotFound,
        'already_reversed' => DebitFailureReason::AlreadyReversed,
        'window_expired' => DebitFailureReason::WindowExpired,
    ];

    public const CREATE_FAILURE_CODES = [
        'duplicate_bvn' => AccountCreationFailure::DuplicateBvn,
        'invalid_bvn' => AccountCreationFailure::InvalidIdentity,
        'invalid_nin' => AccountCreationFailure::InvalidIdentity,
        'rejected' => AccountCreationFailure::Rejected,
    ];
}