<?php

declare(strict_types=1);

namespace App\Waas\Enums;

enum DebitFailureReason: string
{
    case InsufficientFunds = 'insufficient_funds';
    case InvalidAccount = 'invalid_account';
    case Rejected = 'rejected';
    // reversal-only
    case OriginalNotFound = 'original_not_found';
    case AlreadyReversed = 'already_reversed';
    case WindowExpired = 'window_expired';
}