<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case PurchaseCredit = 'purchase_credit';
    case SessionDebit = 'session_debit';
    case ExpiryDebit = 'expiry_debit';
    case CancelDebit = 'cancel_debit';
    case Adjustment = 'adjustment';
}
