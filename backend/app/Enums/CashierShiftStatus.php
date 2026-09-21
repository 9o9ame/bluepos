<?php

namespace App\Enums;

enum CashierShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Voided = 'voided';
}
