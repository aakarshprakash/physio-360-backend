<?php

use App\Support\BlindIndex;

it('matches phone numbers regardless of formatting', function () {
    $a = BlindIndex::phone('+91 98765 43210');

    expect(BlindIndex::phone('09876543210'))->toBe($a)
        ->and(BlindIndex::phone('98765-43210'))->toBe($a)
        ->and(BlindIndex::phone('+91 98765 43211'))->not->toBe($a);
});

it('normalises email case and whitespace', function () {
    expect(BlindIndex::email(' Anil.Kumar@Example.com '))->toBe(BlindIndex::email('anil.kumar@example.com'));
});

it('does not index too-short or empty values', function () {
    expect(BlindIndex::phone('123'))->toBeNull()
        ->and(BlindIndex::email(''))->toBeNull();
});

it('produces keyed hashes, not plain digests', function () {
    expect(BlindIndex::email('a@b.co'))->not->toBe(hash('sha256', 'a@b.co'))
        ->and(BlindIndex::phonesMatch('+91 98765 43210', '9876543210'))->toBeTrue()
        ->and(BlindIndex::phonesMatch('+91 98765 43210', '+91 98765 43299'))->toBeFalse();
});
