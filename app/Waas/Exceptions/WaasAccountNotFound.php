<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

use App\Waas\Support\Mask;

final class WaasAccountNotFound extends WaasException
{
    public function __construct(string $account)
    {
        parent::__construct('WaaS account not found: '.Mask::account($account));
    }
}