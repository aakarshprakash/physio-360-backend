<?php

namespace App\Enums;

/**
 * new ──▶ contacted ⇄ follow_up ──▶ converted (terminal)
 *  └──────────┴──────────┴────────▶ lost ──reopen──▶ new
 */
enum EnquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case FollowUp = 'follow_up';
    case Converted = 'converted';
    case Lost = 'lost';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::FollowUp, self::Lost],
            self::Contacted => [self::FollowUp, self::Lost],
            self::FollowUp => [self::Contacted, self::Lost],
            self::Lost => [self::New],
            self::Converted => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::FollowUp], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::New->value, self::Contacted->value, self::FollowUp->value];
    }
}
