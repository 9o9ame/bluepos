<?php

namespace App\Enums;

enum InventoryAdjustmentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
