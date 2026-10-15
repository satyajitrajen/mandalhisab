<?php

namespace App\Enums;

enum PaymentGateway: string
{
    case RAZORPAY = 'razorpay';
    case APPLE = 'apple';
}
