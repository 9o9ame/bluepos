<?php

namespace App\Enums;

enum PurchaseInvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
