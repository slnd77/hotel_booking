<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\AmenityCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AmenityCategoryAndAmenitySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'property' => [
                'Smoking Policy' => [
                    'All rooms non smoking', 'Smoking permitted in rooms',
                ],
                'Property Amenities' => [
                    'APARTMENTS', 'SUITES', 'BAR_LOUNGE', 'BEACH', 'BED_BREAKFAST', 'BUSINESS_SERVICES',
                    'CAR_RENTAL_DESK', 'CREDIT_CARDS', 'DINING', 'DRY_CLEANING', 'EARLY_ARRIVAL', 'LATE_ARRIVAL',
                    'LATE_CHECKOUT', 'ELDER_ACCESS', 'FITNESS_CENTER', 'FOOD_AVAILABLE', 'FREE_BREAKFAST',
                    'FREE_CANCELLATION', 'FREE_INTERNET', 'FREE_LOCAL_CALLS', 'FREE_PARKING', 'GAME_ROOM', 'GOLF',
                    'KIDS_ACTIVITIES', 'LOCKERS', 'MEETING_ROOM', 'NON_SMOKING', 'PARKING_AVAILABLE', 'ROOM_SERVICE',
                    'PETS_ALLOWED', 'RESTAURANT', 'ELEVATOR', 'SWIMMING_POOL', 'TENNIS_COURT', 'VALET_PARKING',
                    'WHEELCHAIR_ACCESS',
                ],
            ],
            'room_category' => [
                'Smoking Policy' => [
                    'All rooms non smoking', 'Smoking permitted in rooms',
                ],
                'Liquor Policy' => [
                    'Liquor served on premises', 'No liquor policy',
                ],
                'Room amenity type' => [
                    'Adjoining rooms', 'Air conditioning', 'Alarm clock', 'All news channel', 'AM/FM radio',
                    'Baby listening device', 'Balcony/Lanai/Terrace', 'Barbeque grills', 'Bath tub with spray jets',
                    'Bathrobe', 'Bathroom amenities', 'Bathroom telephone', 'Bathtub', 'Bathtub only',
                    'Bathtub/shower combination', 'Bidet', 'Bottled water', 'Cable television', 'Coffee/Tea maker',
                    'Color television', 'Computer', 'Connecting rooms', 'Converters/ Voltage adaptors', 'Copier',
                    'Cordless phone', 'Cribs', 'Data port', 'Desk', 'Desk with lamp', 'Dining guide',
                    'Direct dial phone number', 'Dishwasher', 'Double beds', 'Dual voltage outlet',
                    'Electrical current voltage', 'Ergonomic chair', 'Extended phone cord', 'Fax machine', 'Fire alarm',
                    'Fire alarm with light', 'Fireplace', 'Free toll free calls', 'Free calls',
                    'Free credit card access calls', 'Free local calls', 'Free movies/video', 'Full kitchen',
                    'Grab bars in bathroom', 'Grecian tub', 'Hairdryer', 'High speed internet connection',
                    'Interactive web TV', 'International direct dialing', 'Internet access', 'Iron', 'Ironing board',
                    'Whirlpool / Jacuzzi', 'King bed', 'Kitchen', 'Kitchen supplies', 'Kitchenette', 'Knock light',
                    'Laptop', 'Large desk', 'Large work area', 'Laundry basket/clothes hamper', 'Loft', 'Microwave',
                    'Minibar', 'Modem', 'Modem jack', 'Multi-line phone', 'Newspaper', 'Non-smoking', 'Notepads',
                    'Office supplies', 'Oven', 'Pay per view movies on TV', 'Pens', 'Phone in bathroom',
                    'Plates and bowls', 'Pots and pans', 'Prayer mats', 'Printer', 'Private bathroom', 'Queen bed',
                    'Recliner', 'Refrigerator', 'Refrigerator with ice maker', 'Remote control television',
                    'Rollaway bed', 'Safe', 'Scanner', 'Separate closet', 'Separate modem line available',
                    'Shoe polisher', 'Shower only', 'Silverware/utensils', 'Sitting area', 'Smoke detectors', 'Smoking',
                    'Sofa bed', 'Speaker phone', 'Stereo', 'Stove', 'Tape recorder', 'Telephone',
                    'Telephone for hearing impaired', 'Telephones with message light', 'Toaster oven',
                    'Trouser/Pant press', 'Turn down service', 'Twin bed', 'Vaulted ceilings', 'VCR movies',
                    'VCR player', 'Video games', 'Voice mail', 'Wake-up calls', 'Water closet',
                    'Water purification system', 'Wet bar', 'Wireless internet connection', 'Wireless keyboard',
                    'Adaptor available for telephone PC use', 'Air conditioning individually controlled in room',
                    'Bathtub & whirlpool separate', 'Telephone with data ports', 'CD player',
                    'Complimentary local calls time limit', 'Extra person charge for rollaway use',
                    'Down/feather pillows', 'Desk with electrical outlet', 'ESPN available', 'Foam pillows',
                    'HBO available', 'High ceilings', 'Marble bathroom', 'List of movie channels available',
                    'Pets allowed', 'Over-sized bathtub', 'Shower', 'Sink in-room', 'Soundproofed room',
                    'Storage space', 'Tables and chairs', 'Two-line phone', 'Walk-in closet', 'Washer/dryer',
                    'Weight scale', 'Welcome gift', 'Spare electrical outlet available at desk',
                    'Non-refundable charge for pets', 'Refundable deposit for pets', 'Separate tub and shower',
                    'Entrance type to guest room', 'Ceiling fan', 'CNN available', 'Electrical adaptors available',
                    'Buffet breakfast', 'Accessible room', 'Closets in room', 'DVD player', 'Mini-refrigerator',
                    'Separate line billing for multi-line phone', 'Self-controlled heating/cooling system', 'Toaster',
                    'Analog data port', 'Collect calls', 'International calls', 'Carrier access', 'Interstate calls',
                    'Intrastate calls', 'Local calls', 'Long distance calls', 'Operator-assisted calls',
                    'Credit card access calls', 'Calling card calls', 'Toll free calls', 'Universal AC/DC adaptors',
                    'Bathtub seat', 'Canopy/poster bed', 'Cups/glassware', 'Entertainment center',
                    'Family/oversized room', 'Hypoallergenic bed', 'Hypoallergenic pillows', 'Lamp',
                    'Meal included - breakfast', 'Meal included - continental breakfast', 'Meal included - dinner',
                    'Meal included - lunch', 'Shared bathroom', 'Telephone TDD/Textphone', 'Water bed',
                    'Extra child charge', 'Extra child charge for rollaway use',
                    'Meal included: full American breakfast', 'Futon', 'Murphy bed', 'Tatami mats', 'Single bed',
                    'Annex room', 'Free newspaper', 'Honeymoon suites', 'Complimentary high speed internet in room',
                    'Maid service', 'PC hook-up in room', 'Satellite television', 'VIP rooms', 'Cell phone recharger',
                    'DVR player', 'iPod docking station', 'Media center', 'Plug & play panel', 'Satellite radio',
                    'Video on demand', 'Exterior corridors', 'Gulf view', 'Interior corridors', 'Mountain view',
                    'Ocean view', 'High speed internet access fee', 'High speed wireless', 'Premium movie channels',
                    'Slippers', 'First nighters kit', 'Chair provided with desk', 'Pillow top mattress', 'Feather bed',
                    'Duvet', 'Luxury linen type', 'International channels', 'Pantry', 'Dish-cleaning supplies',
                    'Double vanity', 'Lighted makeup mirror', 'Upgraded bathroom amenities',
                    'VCR player available at front desk', 'Instant hot water', 'Outdoor space', 'Hinoki tub',
                    'Private pool', 'HD Flat Panel Television - 32 inches or greater', 'Room windows open',
                    'Bedding type unknown or unspecified', 'Full bed', 'Round bed', 'TV', 'Child rollaway',
                    'DVD player available at front desk', 'Video game player',
                    'Video game player available at front desk', 'Dining room seats', 'Full size mirror',
                    'Mobile/cellular phones', 'Movies', 'Multiple closets', 'Plates/glassware',
                    'Safe large enough to accommodate a laptop', 'Bed linen thread count', 'Blackout curtain',
                    'Bluray player', 'Device with mp3', 'No adult channels or adult channel lock',
                    'Non-allergenic room', 'Pillow type', 'Seating area with sofa/chair', 'Separate toilet area',
                    'Web enabled', 'Widescreen TV', 'Other data connection', 'Phoneline billed separately',
                    'Separate tub or shower', 'Roof ventilator', 'Childrens playpen', 'Plunge pool',
                ],
                'Common amenity type' => [
                    'Hanger', 'Private entrance', 'Private living room', 'Lock on bedroom door',
                    'Extra pillows and blankets', 'Ethernet connection', 'Pocket wifi', 'Laptop friendly',
                ],
                'Kitchen amenity type' => [
                    'Dishes and silverware', 'Cooking basic',
                ],
                'Facility amenity type' => [
                    'Free parking', 'Street parking', 'Paid parking', 'Paid parking on premises', 'EV Charger', 'Gym',
                    'Pool', 'single level home',
                ],
                'Outdoor amenity type' => [
                    'Bbq area', 'Patio or balcony', 'Garden or backyard',
                ],
                'Special amenity type' => [
                    'Beach essential',
                ],
                'Homesafety amenity type' => [
                    'Fire extinguisher', 'Carbon monoxide detector', 'First aid kit',
                ],
                'Location amenity type' => [
                    'Beach front', 'Lake access', 'Ski in ski out', 'Water front',
                ],
                'Family amenity type' => [
                    'Baby bath', 'Baby monitor', 'Babysitter recommendations', 'Changing table',
                    'Children books and toys', 'Children Dinnerware', 'Fireplace guard', 'High chair', 'Outlet covers',
                    'Pack and play travel crib', 'Room darkening shades', 'Stair gate', 'Table corner guard',
                    'Window guards',
                ],
                'Accessibility amenity type' => [
                    'Wide halfway clearance', 'Home step free access', 'Elevators', 'Path to entrance lit at night',
                    'Home wide doorway', 'Flat smooth pathway to front door', 'Disabled parking spot',
                    'Bedroom step free access', 'Wide Clearance to bed', 'Bedroom wide doorway',
                    'Accessible height bed', 'Electric profiling bed', 'Bathroom step free access',
                    'Accessible height toilet', 'Rollin shower', 'shower chair', 'Bathroom wide doorway',
                    'Tub with shower bench', 'Wide clearance to showerand toilet', 'Handheld shower head',
                    'Common space step free access', 'Common space wide doorway', 'Mobile hoist', 'Pool hoist',
                    'Celling hoist',
                ],
            ],
        ];

        foreach ($data as $type => $categories) {
            foreach ($categories as $categoryName => $amenityNames) {
                $category = AmenityCategory::query()->firstOrCreate(
                    ['name' => $categoryName],
                    ['status' => true],
                );

                foreach ($amenityNames as $name) {
                    Amenity::query()->firstOrCreate(
                        ['key' => Str::slug($name, '_'), 'type' => $type],
                        ['name' => $name, 'amenity_category_id' => $category->id],
                    );
                }
            }
        }
    }
}
