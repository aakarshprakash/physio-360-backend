<?php

namespace App\Services\Scheduling;

use RuntimeException;

/** Raised when a booking would overlap the practitioner, room or patient. Rendered as HTTP 409. */
class SlotConflict extends RuntimeException
{
    /**
     * @param  list<array{resource: string, appointment_id: int, starts_at: string, ends_at: string, local_start?: string}>  $conflicts
     */
    public function __construct(public readonly array $conflicts, string $message = 'The requested time overlaps an existing appointment.')
    {
        parent::__construct($message);
    }
}
