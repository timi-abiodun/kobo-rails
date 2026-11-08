<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

final class WaasCapabilityUnsupported extends WaasException
{
    public function __construct(public readonly string $operation)
    {
        parent::__construct("WaaS operation not supported by provider: {$operation}.");
    }
}