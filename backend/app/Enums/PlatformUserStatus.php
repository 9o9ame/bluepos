<?php

namespace App\Enums;

enum PlatformUserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
