<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
