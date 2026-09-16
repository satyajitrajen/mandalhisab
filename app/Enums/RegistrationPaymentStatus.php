<?php

namespace App\Enums;

enum RegistrationPaymentStatus: string
{
    case CREATED = 'CREATED';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
}
