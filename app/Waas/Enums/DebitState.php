<?php

declare(strict_types=1);

namespace App\Waas\Enums;

enum DebitState: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';       // definitely no money moved
    case Pending = 'pending';     // getDebitStatus only: still processing
    case NotFound = 'not_found';  // getDebitStatus only: provider has no record of this key
}