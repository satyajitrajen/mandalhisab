<?php

namespace App\Enums;

enum FundBucket: string
{
    case CASH_TREASURER = 'CASH_TREASURER';
    case CASH_COLLECTORS = 'CASH_COLLECTORS';
    case BANK = 'BANK';
    case UPI = 'UPI';
}
