<?php

declare(strict_types=1);

namespace App\Waas\Enums;

enum AccountCreationFailure: string
{
    case DuplicateBvn = 'duplicate_bvn';
    case InvalidIdentity = 'invalid_identity';
    case Rejected = 'rejected';
}