<?php

namespace App\Enums;

/** pending ──▶ approved | rejected; pending/approved ──▶ cancelled */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Requests that hold days against the balance and block overlapping requests. */
    public static function holdingValues(): array
    {
        return [self::Pending->value, self::Approved->value];
    }
}
