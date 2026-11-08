<?php

namespace App\Identity\Exceptions;

use RuntimeException;

/** Messages must be static or contain non-sensitive values only (status codes). Never a BVN/NIN. */
final class IdentityProviderUnavailable extends RuntimeException {}