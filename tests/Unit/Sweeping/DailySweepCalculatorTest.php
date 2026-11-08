<?php

declare(strict_types=1);

use App\Sweeping\DailySweepCalculator;
use App\Sweeping\SweepAction;
use App\Sweeping\SweepDecision;
use App\Sweeping\SweepInput;
use App\Sweeping\SweepInputException;
use App\Sweeping\SweepLimit;
use App\Sweeping\SweepReason;

function sweepUtc(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

/** Defaults: N30,000 takings at 2%, N100,000 balance, N5,000 cap, N1,000 buffer. */
function sweepInput(array $overrides = []): SweepInput
{
    return new SweepInput(...array_merge([
        'takingsKobo' => 3_000_000,
        'rateBps' => 200,
        'balanceKobo' => 10_000_000,
        'now' => sweepUtc('2026-11-06 21:00:00'),
    ], $overrides));
}

function sweepDecide(array $overrides = []): SweepDecision
{
    return (new DailySweepCalculator())->decide(sweepInput($overrides));
}

function sweepErrorMessage(callable $fn): string
{
    try {
        $fn();
    } catch (SweepInputException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('Expected SweepInputException but nothing was thrown.');
}

// Columns: overrides, action, amountKobo, reason, limitedBy
it('decides the sweep', function (array $overrides, SweepAction $action, int $amount, SweepReason $reason, ?SweepLimit $limit) {
    $decision = sweepDecide($overrides);

    expect($decision->action)->toBe($action);
    expect($decision->amountKobo)->toBe($amount);
    expect($decision->reason)->toBe($reason);
    expect($decision->limitedBy)->toBe($limit);
    expect($decision->shouldCallBank())->toBe($action === SweepAction::Execute);
})->with([
    // Demo and rate edges
    'demo: N30,000 at 2% = N600' => [['takingsKobo' => 3_000_000], SweepAction::Execute, 60_000, SweepReason::Executed, null],
    'rate 1% (lower edge)' => [['rateBps' => 100], SweepAction::Execute, 30_000, SweepReason::Executed, null],
    'rate 5% (upper edge)' => [['rateBps' => 500], SweepAction::Execute, 150_000, SweepReason::Executed, null],

    // Floor rounding
    'N33,333.33 at 2% floors 66,666.66 to 66,666' => [['takingsKobo' => 3_333_333], SweepAction::Execute, 66_666, SweepReason::Executed, null],
    'N333.33 at 2% floors 666.66 to 666 kobo' => [['takingsKobo' => 33_333], SweepAction::Execute, 666, SweepReason::Executed, null],
    '50 kobo at 2% = exactly 1 kobo' => [['takingsKobo' => 50], SweepAction::Execute, 1, SweepReason::Executed, null],
    '49 kobo at 2% floors to 0' => [['takingsKobo' => 49], SweepAction::Skip, 0, SweepReason::ZeroSweepAmount, null],
    '1 kobo at 1% floors to 0' => [['takingsKobo' => 1, 'rateBps' => 100], SweepAction::Skip, 0, SweepReason::ZeroSweepAmount, null],

    // Zero sales
    'zero takings' => [['takingsKobo' => 0], SweepAction::Skip, 0, SweepReason::ZeroSales, null],
    'zero takings beats low buffer' => [['takingsKobo' => 0, 'balanceKobo' => 0], SweepAction::Skip, 0, SweepReason::ZeroSales, null],

    // Cap boundaries (cap = 500_000)
    'cap hit exactly: computed 500,000' => [['takingsKobo' => 25_000_000], SweepAction::Execute, 500_000, SweepReason::Executed, null],
    'cap minus 1: computed 499,999' => [['takingsKobo' => 24_999_950], SweepAction::Execute, 499_999, SweepReason::Executed, null],
    'cap plus 1: computed 500,001 is capped' => [['takingsKobo' => 25_000_050], SweepAction::Execute, 500_000, SweepReason::Executed, SweepLimit::Cap],
    // N10M spike: the cap bites here. In the demo, Sweep Guard HOLDS this spike
    // upstream, so this input never reaches the calculator; this is a cap test only.
    'N10M spike: 20,000,000 computed, capped to N5,000' => [['takingsKobo' => 1_000_000_000], SweepAction::Execute, 500_000, SweepReason::Executed, SweepLimit::Cap],

    // Buffer boundaries (buffer = 100_000, sweep = 60_000)
    'balance == sweep + buffer leaves exactly the buffer' => [['balanceKobo' => 160_000], SweepAction::Execute, 60_000, SweepReason::Executed, null],
    'balance 1 kobo short: sweep trimmed to 59,999' => [['balanceKobo' => 159_999], SweepAction::Execute, 59_999, SweepReason::Executed, SweepLimit::Buffer],
    'headroom of 1 kobo executes 1 kobo' => [['balanceKobo' => 100_001], SweepAction::Execute, 1, SweepReason::Executed, SweepLimit::Buffer],
    'balance == buffer: nothing to sweep' => [['balanceKobo' => 100_000], SweepAction::Skip, 0, SweepReason::LowBuffer, null],
    'balance 1 below buffer' => [['balanceKobo' => 99_999], SweepAction::Skip, 0, SweepReason::LowBuffer, null],
    'zero balance' => [['balanceKobo' => 0], SweepAction::Skip, 0, SweepReason::LowBuffer, null],
    'zero balance and zero buffer' => [['balanceKobo' => 0, 'minBufferKobo' => 0], SweepAction::Skip, 0, SweepReason::LowBuffer, null],
    'zero buffer, balance == sweep' => [['balanceKobo' => 60_000, 'minBufferKobo' => 0], SweepAction::Execute, 60_000, SweepReason::Executed, null],
    'cap then buffer both trim: Buffer is reported' => [['takingsKobo' => 1_000_000_000, 'balanceKobo' => 200_000], SweepAction::Execute, 100_000, SweepReason::Executed, SweepLimit::Buffer],

    // Cap of zero and minimum sweep
    'daily cap 0' => [['dailyCapKobo' => 0], SweepAction::Skip, 0, SweepReason::ZeroSweepAmount, null],
    'minSweep above the cap' => [['takingsKobo' => 1_000_000_000, 'minSweepKobo' => 500_001], SweepAction::Skip, 0, SweepReason::ZeroSweepAmount, null],
    'minSweep equal to the cap executes' => [['takingsKobo' => 1_000_000_000, 'minSweepKobo' => 500_000], SweepAction::Execute, 500_000, SweepReason::Executed, SweepLimit::Cap],
    'minSweep == computed executes' => [['minSweepKobo' => 60_000], SweepAction::Execute, 60_000, SweepReason::Executed, null],
    'minSweep 1 above computed' => [['minSweepKobo' => 60_001], SweepAction::Skip, 0, SweepReason::ZeroSweepAmount, null],
    'buffer trims below minSweep: LowBuffer, not ZeroSweepAmount' => [['minSweepKobo' => 1_000, 'balanceKobo' => 100_999], SweepAction::Skip, 0, SweepReason::LowBuffer, null],
    'buffer trims to exactly minSweep' => [['minSweepKobo' => 1_000, 'balanceKobo' => 101_000], SweepAction::Execute, 1_000, SweepReason::Executed, SweepLimit::Buffer],
]);

// Columns: overrides, action, amountKobo, reason
// Reference time: now = 2026-11-06 21:00:00 UTC.
it('handles pause', function (array $overrides, SweepAction $action, int $amount, SweepReason $reason) {
    $decision = sweepDecide($overrides);

    expect($decision->action)->toBe($action);
    expect($decision->amountKobo)->toBe($amount);
    expect($decision->reason)->toBe($reason);
})->with([
    'isPaused' => [['isPaused' => true], SweepAction::Skip, 0, SweepReason::Paused],
    'pausedUntil 1 second in the future' => [['pausedUntil' => sweepUtc('2026-11-06 21:00:01')], SweepAction::Skip, 0, SweepReason::Paused],
    'pausedUntil == now is NOT paused' => [['pausedUntil' => sweepUtc('2026-11-06 21:00:00')], SweepAction::Execute, 60_000, SweepReason::Executed],
    'pausedUntil 1 second in the past' => [['pausedUntil' => sweepUtc('2026-11-06 20:59:59')], SweepAction::Execute, 60_000, SweepReason::Executed],
    'isPaused with a past pausedUntil stays paused' => [['isPaused' => true, 'pausedUntil' => sweepUtc('2026-11-01 00:00:00')], SweepAction::Skip, 0, SweepReason::Paused],
    'pause beats zero sales (flag)' => [['isPaused' => true, 'takingsKobo' => 0], SweepAction::Skip, 0, SweepReason::Paused],
    'pause beats zero sales (timed)' => [['pausedUntil' => sweepUtc('2026-11-07 00:00:00'), 'takingsKobo' => 0], SweepAction::Skip, 0, SweepReason::Paused],
    'pause beats low buffer' => [['isPaused' => true, 'balanceKobo' => 0], SweepAction::Skip, 0, SweepReason::Paused],
    'pausedUntil in +01:00 equal to now is NOT paused' => [['pausedUntil' => new DateTimeImmutable('2026-11-06 22:00:00+01:00')], SweepAction::Execute, 60_000, SweepReason::Executed],
    'pausedUntil in +01:00 one second later is paused' => [['pausedUntil' => new DateTimeImmutable('2026-11-06 22:00:01+01:00')], SweepAction::Skip, 0, SweepReason::Paused],
]);

it('rejects invalid input', function (array $overrides) {
    expect(fn () => sweepInput($overrides))->toThrow(SweepInputException::class);
})->with([
    'negative takings' => [['takingsKobo' => -1]],
    'negative balance' => [['balanceKobo' => -1]],
    'negative cap' => [['dailyCapKobo' => -1]],
    'negative buffer' => [['minBufferKobo' => -1]],
    'rate 99 bps' => [['rateBps' => 99]],
    'rate 501 bps' => [['rateBps' => 501]],
    'rate 0' => [['rateBps' => 0]],
    'negative rate' => [['rateBps' => -200]],
    'minSweep 0' => [['minSweepKobo' => 0]],
    'minSweep negative' => [['minSweepKobo' => -1]],
    'takings one above MAX_TAKINGS_KOBO' => [['takingsKobo' => SweepInput::MAX_TAKINGS_KOBO + 1]],
    'invalid input is rejected even when paused' => [['isPaused' => true, 'balanceKobo' => -1]],
]);

it('accepts boundary input', function (array $overrides) {
    expect(sweepInput($overrides))->toBeInstanceOf(SweepInput::class);
})->with([
    'rate 100 bps' => [['rateBps' => 100]],
    'rate 500 bps' => [['rateBps' => 500]],
    'takings exactly MAX_TAKINGS_KOBO' => [['takingsKobo' => SweepInput::MAX_TAKINGS_KOBO]],
    'zero balance, cap and buffer' => [['balanceKobo' => 0, 'dailyCapKobo' => 0, 'minBufferKobo' => 0]],
]);

it('computes at MAX_TAKINGS_KOBO and 5% without overflow, then caps', function () {
    $decision = sweepDecide([
        'takingsKobo' => SweepInput::MAX_TAKINGS_KOBO,
        'rateBps' => 500,
    ]);

    expect($decision->amountKobo)->toBe(500_000);
    expect($decision->limitedBy)->toBe(SweepLimit::Cap);
});

// The message must name the field but never echo a value, so no digits at all.
it('never puts amounts in exception messages', function (array $overrides) {
    $message = sweepErrorMessage(fn () => sweepInput($overrides));

    expect($message)->not->toMatch('/\d/');
    expect($message)->toContain('Invalid sweep input:');
})->with([
    'takings' => [['takingsKobo' => -123_456_789]],
    'balance' => [['balanceKobo' => -987_654_321]],
    'cap' => [['dailyCapKobo' => -555_444_333]],
    'buffer' => [['minBufferKobo' => -222_111_000]],
    'rate low' => [['rateBps' => 99]],
    'rate high' => [['rateBps' => 501]],
    'takings too large' => [['takingsKobo' => SweepInput::MAX_TAKINGS_KOBO + 1]],
]);

it('rejects a float at the call site under strict_types', function () {
    expect(fn () => sweepInput(['takingsKobo' => 1.5]))->toThrow(TypeError::class);
    expect(fn () => sweepInput(['takingsKobo' => 3_000_000.0]))->toThrow(TypeError::class);
});

it('enforces SweepDecision invariants', function () {
    expect(fn () => SweepDecision::execute(0))->toThrow(SweepInputException::class);
    expect(fn () => SweepDecision::execute(-1))->toThrow(SweepInputException::class);
    expect(fn () => SweepDecision::skip(SweepReason::Executed))->toThrow(SweepInputException::class);

    $skip = SweepDecision::skip(SweepReason::LowBuffer);
    expect($skip->amountKobo)->toBe(0);
    expect($skip->limitedBy)->toBe(null);
    expect($skip->shouldCallBank())->toBeFalse();
});

it('maps reasons onto daily_sweeps statuses', function () {
    expect(SweepReason::Executed->dailySweepStatus())->toBe('EXECUTED');
    expect(SweepReason::ZeroSales->dailySweepStatus())->toBe('SKIPPED_ZERO_SALES');
    expect(SweepReason::Paused->dailySweepStatus())->toBe('SKIPPED_PAUSED');
    expect(SweepReason::LowBuffer->dailySweepStatus())->toBe(null);
    expect(SweepReason::ZeroSweepAmount->dailySweepStatus())->toBe(null);
});

it('upholds the sweep invariants across seeded random inputs', function () {
    mt_srand(20261106);

    $calculator = new DailySweepCalculator();
    $caps = [0, 1_000, 500_000, 2_000_000];
    $buffers = [0, 100_000, 500_000];
    $mins = [1, 50, 1_000];
    $seen = ['execute' => 0, 'skip' => 0, 'cap' => 0, 'buffer' => 0, 'paused' => 0, 'zero_sales' => 0, 'low_buffer' => 0, 'zero_sweep' => 0];

    for ($i = 0; $i < 5_000; $i++) {
        $takings = match (mt_rand(0, 3)) {
            0 => 0,
            1 => mt_rand(0, 200),
            2 => mt_rand(0, 5_000_000),
            default => mt_rand(0, 10_000_000_000),
        };
        $paused = mt_rand(0, 9) === 0;

        $input = sweepInput([
            'takingsKobo' => $takings,
            'rateBps' => mt_rand(100, 500),
            'balanceKobo' => mt_rand(0, 20_000_000),
            'isPaused' => $paused,
            'dailyCapKobo' => $caps[mt_rand(0, 3)],
            'minBufferKobo' => $buffers[mt_rand(0, 2)],
            'minSweepKobo' => $mins[mt_rand(0, 2)],
        ]);

        $computed = intdiv($input->takingsKobo * $input->rateBps, 10_000);
        $decision = $calculator->decide($input);

        $fail = fn (string $what) => throw new RuntimeException("Invariant broken ({$what}) at iteration {$i}");

        if ($decision->action === SweepAction::Execute) {
            $seen['execute']++;
            $amount = $decision->amountKobo;

            $paused && $fail('executed while paused');
            $takings === 0 && $fail('executed on zero takings');
            $decision->reason === SweepReason::Executed || $fail('execute reason');
            $decision->shouldCallBank() || $fail('shouldCallBank on execute');
            ($amount >= $input->minSweepKobo && $amount >= 1) || $fail('amount below min');
            $amount <= $input->dailyCapKobo || $fail('amount above cap');
            $amount <= $computed || $fail('amount above floor(takings*rate/10000)');
            ($input->balanceKobo - $amount) >= $input->minBufferKobo || $fail('buffer breached');

            match ($decision->limitedBy) {
                SweepLimit::Cap => ($amount === $input->dailyCapKobo || $fail('limitedBy Cap but amount != cap')) && $seen['cap']++,
                SweepLimit::Buffer => (($input->balanceKobo - $amount) === $input->minBufferKobo || $fail('limitedBy Buffer but buffer not exact')) && $seen['buffer']++,
                null => $amount === $computed || $fail('untrimmed amount != computed'),
            };
        } else {
            $seen['skip']++;

            $decision->amountKobo === 0 || $fail('skip with nonzero amount');
            $decision->shouldCallBank() === false || $fail('shouldCallBank on skip');
            $decision->reason !== SweepReason::Executed || $fail('skip with Executed reason');
            $decision->limitedBy === null || $fail('skip with limitedBy');

            match ($decision->reason) {
                SweepReason::Paused => $paused || $fail('Paused but not paused'),
                SweepReason::ZeroSales => ($takings === 0 && ! $paused) || $fail('ZeroSales precondition'),
                default => null,
            };
            $seen[match ($decision->reason) {
                SweepReason::Paused => 'paused',
                SweepReason::ZeroSales => 'zero_sales',
                SweepReason::LowBuffer => 'low_buffer',
                default => 'zero_sweep',
            }]++;
        }

        // Precedence from the other direction.
        if ($paused) {
            $decision->reason === SweepReason::Paused || $fail('paused must win');
        } elseif ($takings === 0) {
            $decision->reason === SweepReason::ZeroSales || $fail('zero sales must win after pause');
        }
    }

    // Guard against a vacuous run: every branch must actually have been exercised.
    foreach ($seen as $branch => $count) {
        expect($count)->toBeGreaterThan(0);
    }
});