<?php

namespace App\Enums;

enum MemberRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ADMIN = 'ADMIN';
    case TREASURER = 'TREASURER';
    case COLLECTOR = 'COLLECTOR';
    case MEMBER = 'MEMBER';
}
