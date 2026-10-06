<?php

namespace App\Enums;

/**
 * What an outlet sells (Outlets → Settings → Type).
 */
enum OutletType: string
{
    case Others = 'Others';
    case FoodAndBeverages = 'FoodAndBeverages';
    case Laundry = 'Laundry';
    case TravelDesk = 'TravelDesk';
    case Spa = 'Spa';
    case HouseKeeping = 'HouseKeeping';
    case BanquetHall = 'BanquetHall';
    case BusinessCenter = 'BusinessCenter';
    case SouvenirShop = 'SouvenirShop';
    case GiftShop = 'GiftShop';
    case CancellationCharges = 'Cancellation Charges';
    case Default = 'Default';
    case Store = 'Store';
}
