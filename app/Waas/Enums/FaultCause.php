<?php

declare(strict_types=1);

namespace App\Waas\Enums;

/** Why we can't trust the provider's answer. Codes only: never provider text. */
enum FaultCause: string
{
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case ServerError = 'server_error';
    case MalformedResponse = 'malformed_response';
    case PendingResponse = 'pending_response';
}