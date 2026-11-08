<?php

declare(strict_types=1);

use App\Sweeping\RateParser;
use App\Sweeping\SweepInputException;

it('parses valid rates to basis points', function (string $input, int $bps) {
    expect(RateParser::toBps($input))->toBe($bps);
})->with([
    '"2.00"' => ['2.00', 200],
    '"1.5"' => ['1.5', 150],
    '"5"' => ['5', 500],
    '"05"' => ['05', 500],
    '"1"' => ['1', 100],
    '"1.00" (lower edge)' => ['1.00', 100],
    '"5.00" (upper edge)' => ['5.00', 500],
    '"4.99"' => ['4.99', 499],
    '"1.05" (zero-padded decimals)' => ['1.05', 105],
    '"1.50"' => ['1.50', 150],
]);

it('rejects invalid rates', function (string $input) {
    expect(fn () => RateParser::toBps($input))->toThrow(SweepInputException::class);
})->with([
    '"0.99" below 1%' => ['0.99'],
    '"5.01" above 5%' => ['5.01'],
    '"abc"' => ['abc'],
    '"2.005" would need rounding' => ['2.005'],
    'empty string' => [''],
    '"2.00\n" trailing newline' => ["2.00\n"],
    '" 2" leading space' => [' 2'],
    '"2 " trailing space' => ['2 '],
    '"-2" negative' => ['-2'],
    '"2." dangling point' => ['2.'],
    '".5" no integer part' => ['.5'],
    '"2,50" comma' => ['2,50'],
    '"0" zero' => ['0'],
    '"100" three digits' => ['100'],
]);

it('never echoes the input or digits in the exception message', function (string $input) {
    $message = '';

    try {
        RateParser::toBps($input);
    } catch (SweepInputException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('Invalid sweep input:');
    expect($message)->not->toMatch('/\d/');
})->with([
    'out of range' => ['9.99'],
    'malformed' => ['abc'],
    'newline' => ["2.00\n"],
]);