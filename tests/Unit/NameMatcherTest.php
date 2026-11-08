<?php

use App\NameMatching\NameMatcher;
use App\NameMatching\NameMatchMethod;

function nameMatcher(): NameMatcher
{
    return NameMatcher::fromConfig(require dirname(__DIR__, 2).'/config/name_matching.php');
}

// label => [registered (RSA) name, Access Bank account name, expected: match|review|mismatch]
dataset('name_pairs', [
    // reordered
    'reordered'                  => ['OGUNLEYE ADEBAYO', 'Adebayo Ogunleye', 'match'],
    'reordered + extra middle'   => ['Chukwuemeka Obinna Okonkwo', 'OKONKWO CHUKWUEMEKA', 'match'],
    // diacritics
    'yoruba diacritics'          => ["\u{1ECC}la\u{1E63}ubomi Ad\u{E9}yem\u{ED}", 'OLASUBOMI ADEYEMI', 'match'], // Ọlaṣubomi Adéyemí
    'yoruba tone marks'          => ["Ol\u{FA}w\u{E1}seun Ad\u{E9}w\u{E1}l\u{E9}", 'Oluwaseun Adewale', 'match'], // Olúwáseun Adéwálé
    'hausa letters'              => ["\u{18A}anjuma \u{198}asim", 'Danjuma Kasim', 'match'],                      // Ɗanjuma Ƙasim
    // titles, punctuation, business suffixes, spelling variants
    'title and case'             => ['Mr. Adebayo OGUNLEYE', 'adebayo ogunleye', 'match'],
    'chief + hyphen + order'     => ['Chief Okonkwo-Eze Chukwuemeka', 'Chukwuemeka Okonkwo Eze', 'match'],
    'apostrophe'                 => ["N'Dubuisi Okafor", 'Ndubuisi Okafor', 'match'],
    'business suffix'            => ['ADEBAYO OGUNLEYE ENTERPRISES', 'Adebayo Ogunleye', 'match'],
    'muhammad variant'           => ['Mohammed Bello', 'MUHAMMAD BELLO', 'match'],
    'ibrahim variant + title'    => ['Alhaji Musa Ibrahim', 'Ibraheem Musa', 'match'],
    'yusuf variant'              => ['Aminat Yusuf', 'AMINAT YUSUFF', 'match'],
    'fatima variant'             => ['Fatimah Abdullahi', 'FATIMA ABDULLAHI', 'match'],
    // initials
    'leading initial'            => ['A. Ogunleye', 'Adebayo Ogunleye', 'match'],
    'trailing initial'           => ['Ogunleye A.', 'Adebayo Ogunleye', 'match'],
    'middle initial'             => ['Adebayo K. Ogunleye', 'Adebayo Kunle Ogunleye', 'match'],
    'two initials + surname'     => ['A. K. Ogunleye', 'Adebayo Kunle Ogunleye', 'match'],
    'initials only'              => ['A. O.', 'Adebayo Ogunleye', 'review'],
    // near-misses that should pass
    'missing middle name'        => ['Adebayo Ogunleye', 'Adebayo Kunle Ogunleye', 'match'],
    'typo in long given name'    => ['Chukwuemeke Okonkwo', 'Chukwuemeka Okonkwo', 'match'],
    'typo in long given name 2'  => ['Oluwatimileyin Abiodun', 'Oluwatimilehin Abiodun', 'match'],
    // too close to call: blocked + needs_review
    'two letters off, given name' => ['Adebayo Ogunleye', 'Adebanjo Ogunleye', 'review'],
    'one letter off, short surname' => ['Ibrahim Lawal', 'Ibrahim Lawan', 'review'],
    'one letter off, short surname 2' => ['Emmanuel Okafor', 'Emmanuel Okafo', 'review'],
    'single name vs full name'   => ['Adebayo Ogunleye', 'Adebayo', 'review'],
    // different people
    'different people'           => ['Adebayo Ogunleye', 'Ngozi Eze', 'mismatch'],
    'same surname, other given name (Adebayo/Adewale)' => ['Adebayo Ogunleye', 'Adewale Ogunleye', 'mismatch'],
    'same given name, other surname' => ['Adebayo Ogunleye', 'Adebayo Balogun', 'mismatch'],
    'nickname vs full name'      => ['Oluwatimilehin Abiodun', 'Timi Abiodun', 'mismatch'],
    'empty name'                 => ['', 'Adebayo Ogunleye', 'mismatch'],
    'title only'                 => ['Mr', 'Adebayo', 'mismatch'],
]);

it('classifies realistic Nigerian name variants', function (string $a, string $b, string $expected) {
    $r = nameMatcher()->match($a, $b);

    $actual = match (true) {
        $r->matched     => 'match',
        $r->needsReview => 'review',
        default         => 'mismatch',
    };

    expect($actual)->toBe($expected);
})->with('name_pairs');

it('gives the same score and method when the arguments are swapped', function (string $a, string $b) {
    $x = nameMatcher()->match($a, $b);
    $y = nameMatcher()->match($b, $a);

    expect($y->score)->toBe($x->score)->and($y->method)->toBe($x->method);
})->with('name_pairs');

it('never lets Adebayo Ogunleye pass as Adewale Ogunleye, in either direction', function () {
    foreach ([['Adebayo Ogunleye', 'Adewale Ogunleye'], ['Adewale Ogunleye', 'Adebayo Ogunleye']] as [$a, $b]) {
        $r = nameMatcher()->match($a, $b);

        expect($r->matched)->toBeFalse()
            ->and($r->needsReview)->toBeFalse()
            ->and($r->method)->toBe(NameMatchMethod::StringLow)
            ->and($r->score)->toBeLessThanOrEqual($r->lowThreshold);
    }
});

it('reports the method that decided, with exact scores for the easy cases', function () {
    $same = nameMatcher()->match('OGUNLEYE ADEBAYO', 'Adebayo Ogunleye');
    expect($same->method)->toBe(NameMatchMethod::StringHigh)->and($same->score)->toBe(1.0);

    $initial = nameMatcher()->match('A. Ogunleye', 'Adebayo Ogunleye');
    expect($initial->score)->toEqualWithDelta(0.95, 0.0001);

    $review = nameMatcher()->match('Adebayo Ogunleye', 'Adebanjo Ogunleye');
    expect($review->method)->toBe(NameMatchMethod::StringReview)
        ->and($review->matched)->toBeFalse()
        ->and($review->needsReview)->toBeTrue()
        ->and($review->isMismatch())->toBeTrue()
        ->and($review->score)->toEqualWithDelta(0.875, 0.0001)
        ->and($review->strongTokenAgreement)->toBeTrue(); // exact surname + fuzzy given name: batch 3 may use this
});

it('never auto-passes a one-word name, and reports no strong token agreement for it', function () {
    $r = nameMatcher()->match('Adebayo', 'Adebayo Ogunleye');

    expect($r->matched)->toBeFalse()
        ->and($r->needsReview)->toBeTrue()
        ->and($r->strongTokenAgreement)->toBeFalse()
        ->and($r->score)->toBeLessThan($r->highThreshold);
});

it('rejects inverted or out-of-range thresholds', function (float $high, float $low) {
    $config = require dirname(__DIR__, 2).'/config/name_matching.php';
    $config['thresholds'] = ['high' => $high, 'low' => $low];

    NameMatcher::fromConfig($config);
})->with([[0.5, 0.6], [0.6, 0.6], [1.2, 0.5], [0.9, -0.1]])->throws(InvalidArgumentException::class);