<?php

namespace Database\Seeders;

use App\Models\BookingChannel;
use Illuminate\Database\Seeder;

/**
 * The OTAs an OTA booking can come from ("Selected OTA" on the booking form).
 * Safe to re-run: existing OTAs are left as they are.
 */
class BookingChannelSeeder extends Seeder
{
    /** @var list<string> */
    private const OTAS = [
        '123booking',
        'Agoda',
        'Airbnb',
        'Akbar Travels',
        'Booking.com',
        'Cleartrip',
        'EaseMyTrip',
        'Expedia',
        'Goibibo',
        'Happyeasygo',
        'Hostelworld',
        'Hotelbeds',
        'Hotels.com',
        'Ixigo',
        'MakeMyTrip',
        'Paytm Travel',
        'Sawadee Staah',
        'Secretretreats Staah',
        'Sharebooking',
        'Shiji Staah',
        'Silverdoorapartments Staah',
        'Simplebooking',
        'Simplebooking Staah',
        'Simplotel',
        'Simplotel Staah',
        'Sireontours',
        'Situ Staah',
        'Smartbox',
        'Smartie Staah',
        'Spabreaks Staah',
        'Travelguru',
        'Trip.com',
        'Via.com',
        'Yatra',
    ];

    public function run(): void
    {
        foreach (self::OTAS as $name) {
            BookingChannel::query()->firstOrCreate(['name' => $name]);
        }
    }
}
