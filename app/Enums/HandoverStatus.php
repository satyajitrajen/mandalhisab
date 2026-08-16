<?php

namespace App\Enums;

enum HandoverStatus: string
{
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case VERIFIED_ACCEPTED = 'VERIFIED_ACCEPTED';
    case REJECTED = 'REJECTED';
}
