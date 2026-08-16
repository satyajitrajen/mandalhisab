<?php

namespace App\Enums;

enum FestivalStatus: string
{
    case ACTIVE = 'ACTIVE';
    case UPCOMING = 'UPCOMING';
    case COMPLETED = 'COMPLETED';
}
