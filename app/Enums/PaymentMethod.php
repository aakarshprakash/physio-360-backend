<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Upi = 'upi';
    case BankTransfer = 'bank_transfer';
    case Insurance = 'insurance';
    case Online = 'online';
}
