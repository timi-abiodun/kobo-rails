<?php

namespace App\Rails;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Enums\PaymentChannel;

class MockRailClient implements PaymentRailClient
{
    public function name(): string
    {
        return 'mock';
    }

    public function listInflows(string $nuban, CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('mock_rail_transactions')
            ->where('nuban', $nuban)
            ->where('paid_at', '>=', $from->copy()->utc())
            ->where('paid_at', '<', $to->copy()->utc())
            ->orderBy('paid_at')
            ->get()
            ->map(fn ($r) => $this->hydrate($r))
            ->all();
    }

    public function simulatePayment(string $nuban, int $amountKobo, PaymentChannel $channel = PaymentChannel::POS, ?string $reference = null): RailInflow
    {
        $reference ??= 'MOCK-TX-'.Str::upper(Str::random(8));

        DB::table('mock_rail_transactions')->insertOrIgnore([
            'reference' => $reference,
            'nuban' => $nuban,
            'amount_kobo' => $amountKobo,
            'channel' => $channel->value,
            'terminal_id' => $channel === PaymentChannel::POS ? 'MOCK-T0001' : null,
            'paid_at' => now()->utc(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->hydrate(
            DB::table('mock_rail_transactions')->where('reference', $reference)->first()
        );
    }

    public function health(): bool
    {
        return true;
    }

    private function hydrate(object $r): RailInflow
    {
        return new RailInflow(
            $r->reference, $r->nuban, (int) $r->amount_kobo, PaymentChannel::from($r->channel),
            CarbonImmutable::parse($r->paid_at)->utc(), $r->terminal_id, true,
        );
    }
}
