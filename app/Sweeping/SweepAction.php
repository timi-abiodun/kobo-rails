<?php

declare(strict_types=1);

namespace App\Sweeping;

enum SweepAction: string
{
    case Execute = 'execute';
    case Skip = 'skip';
}