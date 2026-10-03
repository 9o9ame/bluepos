<?php

namespace App\Enums;

enum SaleLineKind: string
{
    case Sale = 'sale';
    case FreePackaging = 'free_packaging';
    case FreeScheme = 'free_scheme';
}
