<?php

namespace App\Enums;

/**
 * Groups charge codes for routing. AllCharges only exists on routing rules, as a catch-all.
 */
enum ChargeCategory: string
{
    case RoomAndTax = 'room_and_tax';
    case FoodAndBeverage = 'fnb';
    case Incidentals = 'incidentals';
    case Payment = 'payment';
    /** A balance moved between two folio windows (nets to zero across the booking). */
    case Transfer = 'transfer';
    case AllCharges = 'all_charges';
}
