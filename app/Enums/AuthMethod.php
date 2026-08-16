<?php

namespace App\Enums;

enum AuthMethod: string
{
    case BIOMETRIC = 'BIOMETRIC';
    case PIN = 'PIN';
}
