<?php

declare(strict_types=1);

use App\Waas\Http\WireFormat;
use App\Waas\IdempotencyKeys;
use App\Waas\Support\Mask;
use App\Waas\Support\Reference;
use App\Waas\Support\WireMoney;

it('builds sweep keys, bumping the attempt suffix only after attempt 1', function () {
    expect(IdempotencyKeys::forSweep('m-1', '2026-11-08'))->toBe('sweep:m-1:2026-11-08')
        ->and(IdempotencyKeys::forSweep('m-1', new DateTimeImmutable('2026-11-08'), 2))->toBe('sweep:m-1:2026-11-08:2')
        ->and(IdempotencyKeys::forReversal('sweep:m-1:2026-11-08'))->toBe('rev:sweep:m-1:2026-11-08')
        ->and(IdempotencyKeys::forReversal('sweep:m-1:2026-11-08', 3))->toBe('rev:sweep:m-1:2026-11-08:3');
});

it('rejects bad dates, attempts, charsets and over-long keys', function (Closure $fn) {
    expect($fn)->toThrow(InvalidArgumentException::class);
})->with([
    'bad date' => [fn () => IdempotencyKeys::forSweep('m-1', '08/11/2026')],
    'attempt 0' => [fn () => IdempotencyKeys::forSweep('m-1', '2026-11-08', 0)],
    'bad charset' => [fn () => IdempotencyKeys::forSweep('m 1', '2026-11-08')],
    'too long' => [fn () => IdempotencyKeys::forSweep(str_repeat('x', 60), '2026-11-08')],
]);

it('masks accounts to the last four digits', function () {
    expect(Mask::account('0123456789'))->toBe('******6789')->and(Mask::account('12'))->toBe('****');
});

it('accepts null and UUID references, rejects BVN-shaped ones', function () {
    Reference::assertValid(null);
    Reference::assertValid('a1b2c3d4-0000-4000-8000-000000000001');

    expect(fn () => Reference::assertValid('55555555555'))->toThrow(InvalidArgumentException::class);
});

it('converts kobo on the wire without floats', function () {
    expect(WireMoney::toWire(150_000))->toBe(150_000)
        ->and(WireMoney::fromWire(150_000))->toBe(150_000)
        ->and(WireMoney::fromWire('150000'))->toBe(150_000)
        ->and(WireMoney::fromWire('-500'))->toBe(-500)
        ->and(WireMoney::fromWire(1500.5))->toBeNull()
        ->and(WireMoney::fromWire('1500.50'))->toBeNull()
        ->and(WireMoney::fromWire('abc'))->toBeNull();
})->skip(WireFormat::AMOUNT_UNIT !== 'kobo', 'kobo wire unit only');