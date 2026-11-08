<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Waas\Data\DebitDestination;
use App\Waas\Data\DebitResult;
use App\Waas\Data\WaasAccount;
use App\Waas\Data\WalletRef;
use App\Waas\Enums\FaultCause;
use App\Waas\Http\HttpWaasClient;
use App\Waas\Http\WireFormat;
use App\Waas\WaasClient;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

final class WaasTestKit
{
    public const BVN = '12345678901';
    public const BVN_2 = '12345678902';
    public const NIN = '98765432109';
    public const SRC_NUBAN = '0123456789';
    public const OTHER_NUBAN = '0198765432';
    public const DEST = '2012345678';
    public const BASE_URL = 'https://waas.example.test';
    public const API_KEY = 'sk_test_SECRET_KEY_123';
    public const BODY_MARKER = 'SECRET_BODY_MARKER';
    public const KEY = 'sweep:m-1:2026-11-08';

    /** Must never appear in any exception message. */
    public const SECRETS = [
        self::BVN, self::BVN_2, self::NIN, self::SRC_NUBAN, self::OTHER_NUBAN, self::DEST,
        'waas.example.test', self::API_KEY, self::BODY_MARKER,
    ];

    /** Must not appear in a trace either (every param carrying these is #[\SensitiveParameter]). */
    public const IDENTITY_SECRETS = [self::BVN, self::BVN_2, self::NIN];

    /** scenario => cause for reads/creates that must throw WaasUnavailable */
    public const TRANSIENT = [
        'http_500' => FaultCause::ServerError,
        'http_502' => FaultCause::ServerError,
        'http_503' => FaultCause::ServerError,
        'http_408' => FaultCause::ServerError,
        'http_429' => FaultCause::ServerError,
        'timeout' => FaultCause::Timeout,
        'connection_failed' => FaultCause::ConnectionFailed,
        'html_200' => FaultCause::MalformedResponse,
        'http_418_empty' => FaultCause::MalformedResponse,
    ];

    /** scenario => cause for debits/reversals that must throw DebitOutcomeUnknown */
    public const UNKNOWN = [
        'http_500' => FaultCause::ServerError,
        'http_502' => FaultCause::ServerError,
        'http_503' => FaultCause::ServerError,
        'http_408' => FaultCause::ServerError,
        'http_409' => FaultCause::ServerError,
        'http_429' => FaultCause::ServerError,
        'timeout' => FaultCause::Timeout,
        'connection_failed' => FaultCause::ConnectionFailed,
        'html_200' => FaultCause::MalformedResponse,
        'http_400_html' => FaultCause::MalformedResponse,
        'http_418_empty' => FaultCause::MalformedResponse,
        'json_200_unknown_status' => FaultCause::MalformedResponse,
        'json_200_pending' => FaultCause::PendingResponse,
    ];

    /** @param array<string, mixed> $map  @return array<string, array{0: string, 1: mixed}> */
    public static function dataset(array $map): array
    {
        $out = [];
        foreach ($map as $name => $value) {
            $out[$name] = [$name, $value];
        }

        return $out;
    }

    /** @param array<string, bool> $capabilities */
    public static function real(array $capabilities = ['balance' => true, 'reversal' => true]): HttpWaasClient
    {
        return new HttpWaasClient(self::BASE_URL, self::API_KEY, ['connect' => 1, 'read' => 2, 'debit' => 3], $capabilities);
    }

    public static function dob(): DateTimeImmutable
    {
        return new DateTimeImmutable('1990-05-17');
    }

    /** @param array<string, mixed> $dot dot-path => value, expanded with Arr::undot */
    public static function fakeJson(array $dot, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(Arr::undot($dot), $status)]);
    }

    public static function fake(string $scenario): \ArrayObject
    {
        $calls = new \ArrayObject();
        $m = self::BODY_MARKER;
        Http::preventStrayRequests();

        if ($scenario === 'timeout' || $scenario === 'connection_failed') {
            $text = $scenario === 'timeout' ? 'cURL error 28: Operation timed out' : 'cURL error 7: Failed to connect';
            // The message carries the URL and a body marker on purpose: none of it may surface.
            Http::fake(function ($request) use ($calls, $text, $m) {
                $calls[] = $request;
                throw new ConnectionException("{$text} for ".self::BASE_URL." {$m}");
            });

            return $calls;
        }

        $response = match ($scenario) {
            'http_500', 'http_502', 'http_503', 'http_408', 'http_409', 'http_429'
                => Http::response(Arr::undot(['data.message' => $m]), (int) substr($scenario, 5, 3)),
            'http_418_empty' => Http::response([], 418),
            'http_400_html' => Http::response("<html>{$m}</html>", 400),
            'html_200' => Http::response("<html>{$m}</html>", 200),
            'json_200_unknown_status' => Http::response(Arr::undot([WireFormat::R_STATUS => 'weird', 'data.message' => $m]), 200),
            'json_200_pending' => Http::response(Arr::undot([WireFormat::R_STATUS => 'pending', 'data.message' => $m]), 200),
            default => throw new InvalidArgumentException("Unknown scenario {$scenario}"),
        };

        Http::fake(function ($request) use ($calls, $response) {
            $calls[] = $request;
            return $response;
        });

        return $calls;
    }

    public static function debit(
        WaasClient $c,
        int $amountKobo = 50_000,
        string $key = self::KEY,
        string $nuban = self::SRC_NUBAN,
    ): DebitResult {
        return $c->debitWallet(WalletRef::fromNuban($nuban), $amountKobo, new DebitDestination(self::DEST, '044'), $key, 'Kobo Kobo daily sweep');
    }

    public static function create(
        WaasClient $c,
        #[\SensitiveParameter] string $bvn = self::BVN,
        string $phone = '+2348031234567',
        ?string $reference = null,
        string $name = 'Blessing Okon',
        #[\SensitiveParameter] ?string $nin = self::NIN,
    ): WaasAccount {
        return $c->createAccount($name, $phone, $bvn, $nin, self::dob(), $reference);
    }

    /** One settled credit in the teammate-owned mock inflow table. */
    public static function inflow(string $nuban, int $amountKobo): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:sP');

        DB::table('mock_rail_transactions')->insert([
            'reference' => 'TEST-'.Str::uuid(),
            'nuban' => $nuban,
            'amount_kobo' => $amountKobo,
            'channel' => 'POS',
            'terminal_id' => null,
            'paid_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Runs $fn, asserts it throws $class, asserts the exception leaks nothing, and returns it.
     *
     * @param list<string> $extraSecrets
     */
    /**
     * @template T of Throwable
     * @param \Closure $fn
     * @param class-string<T> $class
     * @param array $extraSecrets
     * @return T
     */
    public static function thrown(Closure $fn, string $class, array $extraSecrets = []): Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf($class);
            self::assertNoLeaks($e, $extraSecrets);

            return $e;
        }

        throw new AssertionFailedError("Expected {$class} to be thrown, nothing was.");
    }

        /** @param list<string> $extraSecrets */
    public static function assertNoLeaks(Throwable $e, array $extraSecrets = []): void
    {
        // '' is contained in every string; the empty-reference dataset case passes '' here.
        $extra = array_values(array_filter($extraSecrets, fn (string $s) => $s !== ''));

        foreach ([...self::SECRETS, ...$extra] as $secret) {
            expect($e->getMessage())->not->toContain($secret);
        }
        foreach (self::IDENTITY_SECRETS as $secret) {
            expect($e->getTraceAsString())->not->toContain($secret); // proves #[\SensitiveParameter] is in place
        }
        expect($e->getPrevious())->toBeNull(); // never chain: previous may hold a URL or body
    }
}