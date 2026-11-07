<?php

namespace App\Rails;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use App\Enums\PaymentChannel;

class HydrogenRailClient implements PaymentRailClient
{
    public function __construct(private string $baseUrl, private string $apiKey) {}

    public function name(): string
    {
        return 'hydrogen';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(8)
            ->retry(2, 300, throw: false);
    }

    public function listInflows(string $nuban, CarbonInterface $from, CarbonInterface $to): array
    {
        // TODO: real transactions/statement endpoint + field names
        $res = $this->http()->get('/TODO/transactions', [
            'account' => $nuban,
            'from' => $from->copy()->utc()->toIso8601String(),
            'to' => $to->copy()->utc()->toIso8601String(),
        ])->throw();

        return collect($res->json('items', []))->map(fn ($t) => new RailInflow(
            reference: $t['reference'],
            merchantAccount: $nuban,
            amountKobo: (int) round(((float) $t['amount']) * 100),
            channel: match ($t['channel'] ?? null) {
                'POS_TERMINAL' => PaymentChannel::POS,
                'QR' => PaymentChannel::Qr,
                default => PaymentChannel::NipTransfer,
            },
            paidAt: CarbonImmutable::parse($t['paid_at'])->utc(),
            terminalId: $t['terminal_id'] ?? null,
        ))->all();
    }

    public function simulatePayment(string $nuban, int $amountKobo, PaymentChannel $channel = PaymentChannel::POS, ?string $reference = null): RailInflow
    {
        // TODO: use Hydrogen's test-charge endpoint if the sandbox exposes one
        throw new \RuntimeException('simulatePayment not wired to Hydrogen sandbox yet');
    }

    public function health(): bool
    {
        try {
            return $this->http()->get('/TODO/ping')->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
