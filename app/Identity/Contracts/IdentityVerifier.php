<?php

namespace App\Identity\Contracts;

use App\Identity\Exceptions\IdentityProviderUnavailable;
use App\Identity\IdentityVerificationResult;

interface IdentityVerifier
{
    /**
     * @throws IdentityProviderUnavailable when we cannot get an answer (retryable).
     *         "Invalid" is only ever returned when the provider answered and said so.
     */
    public function verify(string $bvn, ?string $nin = null): IdentityVerificationResult;
}