<?php

namespace App\Identity;

use InvalidArgumentException;
use RuntimeException;

final class IdentityHasher
{
    public function __construct(
        private readonly string $pepper,
        private readonly int $version = 1,
    ) {
        if (strlen($pepper) < 32) {
            throw new RuntimeException('IDENTITY_HASH_PEPPER is missing or too short (min 32 chars).');
        }
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return string 64-char lowercase hex, for bvn_hash VARCHAR(64) */
    public function hashBvn(string $bvn): string
    {
        return $this->hash('bvn', $bvn);
    }

    /** @return string 64-char lowercase hex, for nin_hash VARCHAR(64) */
    public function hashNin(string $nin): string
    {
        return $this->hash('nin', $nin);
    }

    public function matchesBvn(string $bvn, string $storedHash): bool
    {
        return $this->equals($this->hashBvn($bvn), $storedHash);
    }

    public function matchesNin(string $nin, string $storedHash): bool
    {
        return $this->equals($this->hashNin($nin), $storedHash);
    }

    /** Constant-time compare. */
    public function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    public static function isElevenDigits(string $value): bool
    {
        return preg_match('/^\d{11}$/D', $value) === 1;
    }

    private function hash(string $domain, string $value): string
    {
        if (! self::isElevenDigits($value)) {
            // Never echo the value.
            throw new InvalidArgumentException("Invalid {$domain} format: expected exactly 11 digits.");
        }

        // Domain prefix so a BVN and an NIN with the same digits never hash alike.
        return hash_hmac('sha256', $domain.':'.$value, $this->pepper);
    }
}