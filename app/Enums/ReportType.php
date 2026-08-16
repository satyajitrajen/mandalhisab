<?php

namespace App\Enums;

enum ReportType: string
{
    case INCOME_EXPENSE = 'income-expense';
    case COLLECTIONS = 'collections';
    case EXPENSES = 'expenses';
    case COLLECTORS = 'collectors';
    case RECEIPT_BOOKS = 'receipt-books';
    case CASH = 'cash';
    case FINAL_HISAB = 'final-hisab';
}
