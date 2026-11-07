<?php

namespace Database\Seeders;

use App\Models\FormField;
use Illuminate\Database\Seeder;

class FormFieldSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            'star_rating' => [
                ['value' => 1, 'label' => '1 Star'],
                ['value' => 2, 'label' => '2 Stars'],
                ['value' => 3, 'label' => '3 Stars'],
                ['value' => 4, 'label' => '4 Stars'],
                ['value' => 5, 'label' => '5 Stars'],
            ],
            'property_segment' => [
                ['value' => 1, 'label' => 'Store'],
                ['value' => 2, 'label' => 'Business'],
                ['value' => 3, 'label' => 'Leisure'],
                ['value' => 4, 'label' => 'Resort'],
                ['value' => 5, 'label' => 'Boutique'],
                ['value' => 6, 'label' => 'Budget'],
            ],
            'property_type' => [
                ['value' => 1, 'label' => 'Hotel'],
                ['value' => 2, 'label' => 'Resort'],
                ['value' => 3, 'label' => 'Hostel'],
                ['value' => 4, 'label' => 'Bed and Breakfast'],
                ['value' => 5, 'label' => 'Motel'],
                ['value' => 6, 'label' => 'Villa'],
                ['value' => 7, 'label' => 'Serviced Apartment'],
            ],
            'tax_type' => [
                ['value' => 1, 'label' => 'General'],
                ['value' => 2, 'label' => 'Composition'],
                ['value' => 3, 'label' => 'Exempt'],
            ],
            'meal_plans' => [
                ['display_order' => 1, 'constant_name' => 'EP', 'display_name' => 'Room Only', 'short_display_name' => 'EP'],
                ['display_order' => 2, 'constant_name' => 'CP', 'display_name' => 'Breakfast', 'short_display_name' => 'CP'],
                ['display_order' => 3, 'constant_name' => 'MAP', 'display_name' => 'Breakfast and Lunch or Dinner', 'short_display_name' => 'MAP'],
                ['display_order' => 4, 'constant_name' => 'AP', 'display_name' => 'Breakfast, Lunch and Dinner', 'short_display_name' => 'AP'],
                ['display_order' => 5, 'constant_name' => 'MAPAI', 'display_name' => 'MAPAI', 'short_display_name' => 'MAPAI'],
                ['display_order' => 6, 'constant_name' => 'APAI', 'display_name' => 'APAI', 'short_display_name' => 'APAI'],
            ],
            'bed_types' => [
                ['value' => 1, 'label' => 'Double Bed'],
                ['value' => 2, 'label' => 'Futon'],
                ['value' => 3, 'label' => 'King Bed'],
                ['value' => 4, 'label' => 'Murphy Bed'],
                ['value' => 5, 'label' => 'Queen Bed'],
                ['value' => 6, 'label' => 'Sofa Bed'],
                ['value' => 7, 'label' => 'Tatami Mats'],
                ['value' => 8, 'label' => 'Twin Bed'],
                ['value' => 9, 'label' => 'Single Bed'],
                ['value' => 10, 'label' => 'Full'],
                ['value' => 11, 'label' => 'Run of the house'],
                ['value' => 12, 'label' => 'Dorm Bed'],
                ['value' => 13, 'label' => 'Roll-Away Bed'],
                ['value' => 14, 'label' => 'Crib'],
                ['value' => 15, 'label' => 'Unspecified Bed'],
            ],
            'booking_type' => [
                ['value' => 1, 'label' => 'Direct'],
                ['value' => 2, 'label' => 'OTA'],
                ['value' => 3, 'label' => 'Agent'],
                ['value' => 4, 'label' => 'GDS'],
                ['value' => 5, 'label' => 'Corp'],
                ['value' => 6, 'label' => 'Booking Engine'],
                ['value' => 7, 'label' => 'Meta'],
                ['value' => 8, 'label' => 'Other'],
            ],
        ];

        foreach ($fields as $key => $data) {
            FormField::query()->firstOrCreate(['key' => $key], ['data' => $data]);
        }
    }
}
