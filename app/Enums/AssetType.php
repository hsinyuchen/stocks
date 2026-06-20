<?php

namespace App\Enums;

enum AssetType: string
{
    case Stock = 'stock';
    case Etf = 'etf';
    case Index = 'index';
}
