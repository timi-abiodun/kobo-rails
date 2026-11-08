<?php

namespace App\Identity;

use App\Identity\Contracts\IdentityVerifier;
use App\Identity\Exceptions\IdentityProviderUnavailable;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

final class DojahIdentityVerifier implements IdentityVerifier
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function verify(string $bvn, ?string $nin = null): IdentityVerificationResult
    {
        $bvnEntity = $this->lookup($this->config['endpoints']['bvn'], 'bvn', $bvn);

        if ($bvnEntity === null) {
            return IdentityVerificationResult::rejected(IdentityFailureReason::BvnNotFound);
        }

        if ($nin !== null) {
            $ninEntity = $this->lookup($this->config['endpoints']['nin'], 'nin', $nin);

            if ($ninEntity === null) {
                return IdentityVerificationResult::rejected(IdentityFailureReason::NinNotFound);
            }
            if (! $this->sameIdentity($bvnEntity, $ninEntity)) {
                return IdentityVerificationResult::rejected(IdentityFailureReason::NinBvnMismatch);
            }
        }

        return IdentityVerificationResult::verified(
            $this->fullName($bvnEntity),
            $this->dateOfBirth($bvnEntity),
        );
    }

    /** @return array<string,mixed>|null null = provider says "not found" */
    private function lookup(string $path, string $param, string $value): ?array
    {
        try {
            $response = $this->http
                ->baseUrl($this->config['base_url'])
                ->withHeaders([
                    'AppId'         => $this->config['app_id'],       // TODO confirm header names
                    'Authorization' => $this->config['secret_key'],
                ])
                ->acceptJson()
                ->connectTimeout($this->config['connect_timeout'])
                ->timeout($this->config['timeout'])
                ->get($path, [$param => $value]);
        } catch (ConnectionException) {
            // Do NOT attach the previous exception: Guzzle's message contains the full URL,
            // and that URL contains the BVN/NIN in the query string.
            throw new IdentityProviderUnavailable('Identity provider connection failed.');
        }

        if ($response->status() === 404) {      // TODO confirm Dojah's "not found" semantics
            return null;
        }

        if ($response->failed()) {              // 401/403 = our credentials, 429/5xx = theirs; none is the user's fault
            throw new IdentityProviderUnavailable(
                sprintf('Identity provider returned HTTP %d.', $response->status())
            );
        }

        $entity = $response->json('entity');    // TODO confirm response envelope

        if (! is_array($entity)) {
            throw new IdentityProviderUnavailable('Identity provider returned an unexpected response.');
        }

        return $entity;
    }

    private function fullName(array $e): string
    {
        // TODO confirm field names. Order doesn't matter: the name matcher handles reordering.
        $parts = array_filter([$e['first_name'] ?? null, $e['middle_name'] ?? null, $e['last_name'] ?? null]);

        if ($parts === []) {
            throw new IdentityProviderUnavailable('Identity provider returned an unexpected response.');
        }

        return trim(implode(' ', $parts));
    }

    private function dateOfBirth(array $e): DateTimeImmutable
    {
        $raw = (string) ($e['date_of_birth'] ?? '');

        // TODO confirm format; BVN providers vary (Y-m-d, d-m-Y, d/m/Y).
        foreach (['!Y-m-d', '!d-m-Y', '!d/m/Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat($format, $raw);
            if ($d !== false) {
                return $d;
            }
        }

        throw new IdentityProviderUnavailable('Identity provider returned an unexpected response.');
    }

    private function sameIdentity(array $bvnEntity, array $ninEntity): bool
    {
        // TODO decide the real cross-check. Minimum: same surname (case-insensitive) + same DOB.
        return strcasecmp((string) ($bvnEntity['last_name'] ?? ''), (string) ($ninEntity['last_name'] ?? '')) === 0
            && $this->dateOfBirth($bvnEntity) == $this->dateOfBirth($ninEntity);
    }
}