<?php

namespace App\Enums;

enum ReceiptBookStatus: string
{
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case LOST = 'LOST';
    case CANCELLED = 'CANCELLED';
}
