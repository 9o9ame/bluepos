<?php

namespace App\Enums;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case PurchaseAmendment = 'purchase_amendment';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleAmendment = 'sale_amendment';
    case SaleReturn = 'sale_return';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case ClaimOut = 'claim_out';
    case ExpiryOut = 'expiry_out';
}
