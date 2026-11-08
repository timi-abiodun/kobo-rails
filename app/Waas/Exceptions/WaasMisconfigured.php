<?php

declare(strict_types=1);

namespace App\Waas\Exceptions;

use LogicException;

/** Config/boot problem, not a provider problem. Deliberately NOT a WaasException. */
final class WaasMisconfigured extends LogicException {}