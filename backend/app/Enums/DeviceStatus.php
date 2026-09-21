<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
    case Disabled = 'disabled';
}
