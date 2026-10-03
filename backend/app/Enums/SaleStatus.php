<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Posted = 'posted';
    case Void = 'void';
}
