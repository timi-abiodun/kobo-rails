<?php

namespace App\NameMatching;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class NameMismatchAlerts
{
    public const TYPE = 'NAME_MISMATCH';
    public const OPEN = 'PENDING_REVIEW';

    /** Statuses that keep a merchant held: undecided, or confirmed as a genuine mismatch. */
    private const HOLDING = ['PENDING_REVIEW', 'CONFIRMED'];

    /** @return array<string,mixed> shaped for sweep_guard_alerts (PRD v2) */
    public static function toRow(string $merchantId, NameMatchResult $result): array
    {
        return [
            'merchant_id' => $merchantId,
            'alert_type'  => self::TYPE,
            'details'     => json_encode($result->toAlertContext(), JSON_THROW_ON_ERROR),
            'status'      => self::OPEN,
        ];
    }

    /** One open alert per merchant: a retry refreshes details instead of adding a row. */
    public function record(string $merchantId, NameMatchResult $result): void
    {
        if ($result->matched) {
            throw new LogicException('Cannot record a name-mismatch alert for a matched result.');
        }

        $row = self::toRow($merchantId, $result);

        $open = DB::table('sweep_guard_alerts')
            ->where('merchant_id', $merchantId)
            ->where('alert_type', self::TYPE)
            ->where('status', self::OPEN);

        if ($open->exists()) {
            $open->update(['details' => $row['details']]);

            return;
        }

        DB::table('sweep_guard_alerts')->insert($row + ['id' => (string) Str::uuid()]);
    }

    public function isHeld(string $merchantId): bool
    {
        return DB::table('sweep_guard_alerts')
            ->where('merchant_id', $merchantId)
            ->where('alert_type', self::TYPE)
            ->whereIn('status', self::HOLDING)
            ->exists();
    }
}