<?php

namespace App\Enums;

enum PackageStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
