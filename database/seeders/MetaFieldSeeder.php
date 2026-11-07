<?php

namespace Database\Seeders;

use App\Models\MetaField;
use Illuminate\Database\Seeder;

class MetaFieldSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'complementary_inclusions' => 'Add Standard/Common complimentary inclusions',
            'terms_and_conditions_while_booking' => 'Add Standard/Common Terms & Conditions while booking a room at your property.',
            'terms_and_conditions_while_check_in' => 'Add Standard/Common Terms & Conditions to show while check in at your property.',
            'meal_plans' => 'Add Standard/Common meal plans for your property.',
            'checkin_checkout_time' => 'Add Check-in/Check-out time for your property.',
            'weekend_holidays' => 'Add Weekend/Holidays for your property.',
            'checkin_checkout_policy' => 'Add Check-in/Check-out policy for your property.',
            'payment_policy' => 'Add Payment policy for your property.',
            'house_rules' => 'Add House rules for your property.',
            'card_types' => 'Select the card types accepted at your property.',
            'payment_terms' => 'Define payment terms for your property by booking type.',
            'GRC_settings' => 'Configure the Guest Registration Card (GRC) fields for your property.',
            'booking_settings_reservation' => 'Configure reservation settings for your property.',
            'booking_settings_post_master' => 'Configure post master settings for your property.',
            'booking_settings_document' => 'Configure document settings for your property.',
            'booking_settings_printer' => 'Configure printer settings for your property.',
            'booking_settings_auto_release' => 'Configure auto-release settings for your property.',
            'booking_settings_housekeeping' => 'Configure housekeeping settings for your property.',
            'booking_settings_custom_fields' => 'Configure custom fields for your property.',
            'booking_settings_payment_modes' => 'Choose the payment modes offered for payments and refunds at your property.',
            'rate_settings_general' => 'Configure general rate settings for your property.',
            'rate_settings_occupancy_pricing' => 'Configure occupancy-based pricing for your property.',
            'rate_settings_tax' => 'Configure tax settings for your property rates.',
            'rate_settings_rounding' => 'Configure rate rounding settings for your property.',
            'rate_settings_rate_plan' => 'Configure rate plans for your property.',
            'rate_settings_rate_planner' => 'Configure the BAR calendar rate plan mapping for your property.',
            'rate_settings_deals_promotion' => 'Configure deals and promo codes for your property.',
            'rate_settings_packages' => 'Configure packages for your property.',
            'rate_settings_packages_planner' => 'Configure the packages calendar for your property.',
            'tax_settings_tax_category' => 'Configure tax categories for your property.',
            'tax_settings_tax_rule' => 'Configure tax rules for your property.',
            'invoice_settings_trackers' => 'Configure invoice/receipt/proforma/refund/cancellation/credit note trackers for your property.',
            'invoice_settings_general' => 'Configure invoice display, print and billing settings for your property.',
            'booking_engine_settings' => 'Configure booking engine settings for your property.',
            'night_audit_settings' => 'Configure the night audit (rollover) for your property.',
            'report_settings' => 'Configure which reports are emailed for your property, when and to whom.',
            'branding_settings' => 'Configure the logo, colours, images and contact details shown on your booking engine.',
        ])->each(fn (string $label, string $key) => MetaField::query()->firstOrCreate(
            ['key' => $key],
            ['key_label' => $label, 'status' => true],
        ));
    }
}
