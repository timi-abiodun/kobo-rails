<?php

use App\Identity\IdentityHasher;

function hasher(string $pepper = 'pepper-pepper-pepper-pepper-pepper-1'): IdentityHasher
{
    return new IdentityHasher($pepper);
}

it('produces 64-char lowercase hex deterministically', function () {
    $h = hasher()->hashBvn('22200000001');

    expect($h)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hasher()->hashBvn('22200000001'))->toBe($h);
});

it('changes with the pepper', function () {
    expect(hasher('another-pepper-another-pepper-12345')->hashBvn('22200000001'))
        ->not->toBe(hasher()->hashBvn('22200000001'));
});

it('never lets a BVN and NIN with the same digits collide', function () {
    expect(hasher()->hashBvn('22200000001'))->not->toBe(hasher()->hashNin('22200000001'));
});

it('matches stored hashes and rejects others', function () {
    $stored = hasher()->hashNin('11100000001');

    expect(hasher()->matchesNin('11100000001', $stored))->toBeTrue()
        ->and(hasher()->matchesNin('11100000002', $stored))->toBeFalse();
});

$malformed = ['1234567890', '123456789012', '1234567890a', '12345 678901', ''];

it('rejects malformed identifiers', function (string $bad) {
    expect(fn () => hasher()->hashBvn($bad))->toThrow(InvalidArgumentException::class);
    expect(fn () => hasher()->hashNin($bad))->toThrow(InvalidArgumentException::class);
})->with($malformed);

it('never echoes a malformed identifier in the exception message', function (string $bad) {
    try {
        hasher()->hashBvn($bad);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain($bad);

        return;
    }

    $this->fail('expected InvalidArgumentException');
})->with(array_filter($malformed)); // '' is a substring of everything, so it can't be leak-checked

it('refuses a missing or short pepper', function () {
    new IdentityHasher('short');
})->throws(RuntimeException::class);