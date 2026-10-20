<?php

namespace App\Enums;

enum SupportTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingOnClinic = 'waiting_on_clinic';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Open->value, self::InProgress->value, self::WaitingOnClinic->value];
    }
}
