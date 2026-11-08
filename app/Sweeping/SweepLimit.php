<?php

declare(strict_types=1);

namespace App\Sweeping;

/** Which constraint trimmed an executed sweep. Informational only. */
enum SweepLimit: string
{
    case Cap = 'cap';
    case Buffer = 'buffer';
}