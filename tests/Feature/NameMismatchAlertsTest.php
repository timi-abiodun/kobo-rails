<?php

use App\NameMatching\NameMatcher;
use App\NameMatching\NameMismatchAlerts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Stand-in until Caleb's migration lands. Postgres DDL is transactional, so it rolls back too.
    if (! Schema::hasTable('sweep_guard_alerts')) {
        Schema::create('sweep_guard_alerts', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('merchant_id');
            $t->string('alert_type', 50);
            $t->json('details')->nullable();
            $t->string('status', 20)->default('PENDING_REVIEW');
            $t->timestampTz('created_at')->useCurrent();
        });
    }
});

function blockedResult()
{
    return NameMatcher::fromConfig(config('name_matching'))->match('Adebayo Ogunleye', 'Adebanjo Ogunleye');
}

it('maps a result to a sweep_guard_alerts row without names', function () {
    $row = NameMismatchAlerts::toRow('m-1', blockedResult());

    expect($row['alert_type'])->toBe('NAME_MISMATCH')
        ->and($row['status'])->toBe('PENDING_REVIEW')
        ->and(json_decode($row['details'], true))->toBe(blockedResult()->toAlertContext())
        ->and($row['details'])->not->toContain('Ogunleye');
});

it('records one open alert and refreshes it on retry', function () {
    $m = (string) Str::uuid();
    $alerts = new NameMismatchAlerts();

    $alerts->record($m, blockedResult());
    $alerts->record($m, blockedResult());

    expect(DB::table('sweep_guard_alerts')->where('merchant_id', $m)->count())->toBe(1);
});

it('refuses to record a matched result', function () {
    $matched = NameMatcher::fromConfig(config('name_matching'))->match('OGUNLEYE ADEBAYO', 'Adebayo Ogunleye');

    (new NameMismatchAlerts())->record((string) Str::uuid(), $matched);
})->throws(LogicException::class);

it('holds merchants with an open or confirmed alert only', function (string $status, bool $held) {
    $m = (string) Str::uuid();
    (new NameMismatchAlerts())->record($m, blockedResult());
    DB::table('sweep_guard_alerts')->where('merchant_id', $m)->update(['status' => $status]);

    expect((new NameMismatchAlerts())->isHeld($m))->toBe($held);
})->with([['PENDING_REVIEW', true], ['CONFIRMED', true], ['DISMISSED', false]]);

it('does not hold other merchants or other alert types', function () {
    $m = (string) Str::uuid();
    DB::table('sweep_guard_alerts')->insert([
        'id' => (string) Str::uuid(), 'merchant_id' => $m, 'alert_type' => 'LOW_BUFFER',
        'details' => '{}', 'status' => 'PENDING_REVIEW',
    ]);

    expect((new NameMismatchAlerts())->isHeld($m))->toBeFalse()
        ->and((new NameMismatchAlerts())->isHeld((string) Str::uuid()))->toBeFalse();
});