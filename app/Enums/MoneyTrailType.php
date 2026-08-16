<?php

namespace App\Enums;

enum MoneyTrailType: string
{
    case CASH_RECEIVED = 'CASH_RECEIVED';
    case UPI_RECEIVED = 'UPI_RECEIVED';
    case BANK_DEPOSIT = 'BANK_DEPOSIT';
    case BANK_WITHDRAWAL = 'BANK_WITHDRAWAL';
    case CASH_EXPENSE = 'CASH_EXPENSE';
    case UPI_EXPENSE = 'UPI_EXPENSE';
    case CASH_HANDOVER = 'CASH_HANDOVER';
    case FUND_TRANSFER = 'FUND_TRANSFER';
    case OTHER_INCOME = 'OTHER_INCOME';
}
