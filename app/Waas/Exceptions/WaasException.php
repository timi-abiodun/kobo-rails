<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

use RuntimeException;

/** Base for runtime provider problems. Messages must never contain BVN/NIN/full accounts/URLs/bodies. */
abstract class WaasException extends RuntimeException {}