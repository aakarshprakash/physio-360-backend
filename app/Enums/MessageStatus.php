<?php

namespace App\Enums;

/**
 * queued ──▶ sent ──▶ delivered | failed.
 * "manual": no gateway is configured for the channel, so staff send it from
 * their own phone via a prefilled WhatsApp / SMS link; the log still records it.
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Manual = 'manual';
}
