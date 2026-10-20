<?php

namespace App\Enums;

enum MessageChannel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
}
