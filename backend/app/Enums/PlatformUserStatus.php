<?php

namespace App\Enums;

enum PlatformUserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
