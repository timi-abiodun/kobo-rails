<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

use App\Waas\Enums\AccountCreationFailure;

final class AccountCreationRejected extends WaasException
{
    public function __construct(public readonly AccountCreationFailure $reason)
    {
        parent::__construct("WaaS account creation rejected: {$reason->value}.");
    }
}