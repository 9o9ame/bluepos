<?php

namespace App\Enums;

enum SalePaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Bank = 'bank';
    case Credit = 'credit';
}
