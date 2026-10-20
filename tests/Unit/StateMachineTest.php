<?php

use App\Enums\AppointmentStatus as A;
use App\Enums\InvoiceStatus as I;

it('allows only the documented appointment transitions', function (A $from, A $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    [A::Scheduled, A::Confirmed, true],
    [A::Scheduled, A::CheckedIn, true],
    [A::Confirmed, A::CheckedIn, true],
    [A::CheckedIn, A::InProgress, true],
    [A::InProgress, A::Completed, true],
    [A::Confirmed, A::NoShow, true],
    [A::Scheduled, A::Completed, false],
    [A::Completed, A::Cancelled, false],
    [A::Cancelled, A::Scheduled, false],
    [A::NoShow, A::CheckedIn, false],
]);

it('treats cancelled and no-show as non-blocking', function () {
    expect(A::blocking())->not->toContain(A::Cancelled)->not->toContain(A::NoShow)
        ->and(A::blocking())->toContain(A::Completed);
});

it('allows only the documented invoice transitions', function () {
    expect(I::Draft->canTransitionTo(I::Posted))->toBeTrue()
        ->and(I::Posted->canTransitionTo(I::Void))->toBeTrue()
        ->and(I::Posted->canTransitionTo(I::Credited))->toBeTrue()
        ->and(I::Draft->canTransitionTo(I::Void))->toBeFalse()
        ->and(I::Void->canTransitionTo(I::Posted))->toBeFalse()
        ->and(I::Credited->canTransitionTo(I::Void))->toBeFalse();
});
