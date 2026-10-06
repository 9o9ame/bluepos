<?php

namespace App\Enums;

enum StockTakeStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
