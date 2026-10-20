<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case Leave = 'leave';
    case Holiday = 'holiday';
}
