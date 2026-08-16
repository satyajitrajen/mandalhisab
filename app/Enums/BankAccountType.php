<?php

namespace App\Enums;

enum BankAccountType: string
{
    case CURRENT = 'CURRENT';
    case SAVINGS = 'SAVINGS';
}
