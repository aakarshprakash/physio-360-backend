<?php

namespace App\Enums;

/**
 * draft ──post──▶ posted ──void (no net payments, no used package)──▶ void
 *                    └──credit notes reach total──▶ credited
 * Only drafts are editable or deletable. Numbers are assigned on post.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';
    case Credited = 'credited';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Posted],
            self::Posted => [self::Void, self::Credited],
            self::Void, self::Credited => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
