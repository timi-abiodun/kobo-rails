<?php

namespace App\Identity;

use App\Identity\Contracts\IdentityVerifier;
use App\Identity\Exceptions\IdentityProviderUnavailable;
use DateTimeImmutable;

final class MockIdentityVerifier implements IdentityVerifier
{
    public const BVN_NOT_FOUND = '00000000000';
    public const NIN_NOT_FOUND = '00000000000';
    public const BVN_OUTAGE    = '99999999999';

    /** bvn => [full name as the bank/NIBSS would return it, dob, matching nin] */
    private const RECORDS = [
        '22200000001' => ['Adebayo Ogunleye',            '1990-05-14', '11100000001'],
        '22200000002' => ['OKONKWO Chukwuemeka Obinna',  '1988-11-02', '11100000002'],
        '22200000003' => ['Ọlaṣubomi Adéyemí',           '1995-03-21', '11100000003'],
        '22200000004' => ['Bello Aisha',                 '1992-07-30', '11100000004'],
        '22200000005' => ['Adewale Ogunleye',            '1991-01-09', '11100000005'], // near-miss sibling of 001
    ];

    public function verify(string $bvn, ?string $nin = null): IdentityVerificationResult
    {
        if ($bvn === self::BVN_OUTAGE) {
            throw new IdentityProviderUnavailable('Identity provider unavailable (mock outage).');
        }

        $record = self::RECORDS[$bvn] ?? null;

        if ($record === null) {
            return IdentityVerificationResult::rejected(IdentityFailureReason::BvnNotFound);
        }

        [$name, $dob, $recordNin] = $record;

        if ($nin !== null) {
            if ($nin === self::NIN_NOT_FOUND) {
                return IdentityVerificationResult::rejected(IdentityFailureReason::NinNotFound);
            }
            if ($nin !== $recordNin) {
                return IdentityVerificationResult::rejected(IdentityFailureReason::NinBvnMismatch);
            }
        }

        return IdentityVerificationResult::verified($name, new DateTimeImmutable($dob));
    }
}