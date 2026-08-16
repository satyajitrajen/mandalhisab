<?php

namespace App\Enums;

enum PaymentMode: string
{
    case CASH = 'CASH';
    case UPI = 'UPI';
    case CHEQUE = 'CHEQUE';
    case NET_BANKING = 'NET_BANKING';
}
