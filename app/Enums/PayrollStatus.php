<?php

namespace App\Enums;

/** draft (editable) ──finalize──▶ finalized (frozen) ──mark paid──▶ paid */
enum PayrollStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';
}
