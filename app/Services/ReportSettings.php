<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reports (the "Reports" email settings): which reports are emailed,
 * when, and to whom. One JSON document per property in property_meta_fields. The send
 * time is the property's wall-clock time; it only becomes a UTC instant when scheduled.
 */
class ReportSettings
{
    public const KEY = 'report_settings';

    public const DATES_NONE = 'none';

    public const DATES_SINGLE = 'single';

    public const DATES_RANGE = 'range';

    /**
     * The report catalogue, in display order: which date filter it takes, whether it is
     * filtered by staff, and the file types it downloads as.
     *
     * @var array<string, array{name: string, dates: string, staff: bool, downloads: list<string>, subject?: string}>
     */
    public const REPORTS = [
        'daily_reports' => ['name' => 'Daily Reports', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel', 'pdf'], 'subject' => 'Daily Front Office Report'],
        'daily_sales_reports' => ['name' => 'Daily Sales Reports', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel', 'pdf'], 'subject' => 'Daily Sales Report'],
        'occupied_rooms_reports' => ['name' => 'Occupied Rooms Reports', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel', 'pdf'], 'subject' => 'Occupied Rooms Report'],
        'inventory_availability_report' => ['name' => 'Inventory Availability Report', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel']],
        'daily_cashier_report' => ['name' => 'Daily Cashier Report', 'dates' => self::DATES_SINGLE, 'staff' => true, 'downloads' => ['excel', 'pdf']],
        'daily_reputation_reports' => ['name' => 'Daily Reputation Reports', 'dates' => self::DATES_NONE, 'staff' => false, 'downloads' => []],
        'weekly_reputation_reports' => ['name' => 'Weekly Reputation Reports', 'dates' => self::DATES_NONE, 'staff' => false, 'downloads' => []],
        'daily_manager_report' => ['name' => 'Daily Manager Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['pdf']],
        'checked_out_folios_modification_report' => ['name' => 'CheckedOut Folios Modification Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel']],
        'daily_transaction_report' => ['name' => 'Daily Transaction Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel']],
        'daily_housekeeping_report' => ['name' => 'Daily House Keeping Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel']],
        'account_receivables_report' => ['name' => 'Account Receivables Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel']],
        'post_master_report' => ['name' => 'Post Master Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel']],
        'addon_report' => ['name' => 'AddOn Report', 'dates' => self::DATES_SINGLE, 'staff' => false, 'downloads' => ['excel']],
        'gstr1_sales_register_report' => ['name' => 'GSTR-1 Sales Register Report', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel']],
        'gstr3b_summary_return_report' => ['name' => 'GSTR-3B Summary Return Report', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel']],
        'hsn_sac_summary_report' => ['name' => 'HSN/SAC Summary Report', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel']],
        'nationality_revenue_report' => ['name' => 'Nationality Revenue Report', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel', 'pdf']],
        'financial_transaction_summary' => ['name' => 'Financial Transaction Summary', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel']],
        'pos_sales_report' => ['name' => 'POS Sales Report', 'dates' => self::DATES_RANGE, 'staff' => false, 'downloads' => ['excel']],
    ];

    /**
     * The columns a report's download can show ("Select Columns"), in download order.
     * Reports not listed here have a fixed layout.
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        'daily_reports' => [
            's_no' => 'S.No.', 'reservation_no' => 'Reservation No.', 'check_in' => 'Check In', 'check_out' => 'Check Out',
            'source_type' => 'Source Type', 'status' => 'Status', 'current_status' => 'Current Status', 'booking_type' => 'Booking Type',
            'reference_no' => 'Reference No.', 'pay_at_hotel' => 'Pay at Hotel', 'rooms' => 'Rooms', 'room_numbers' => 'Room Numbers',
            'pax' => 'No. of Pax', 'meal_plans' => 'Meal Plans', 'total_adults' => 'Total Adults', 'total_children' => 'Total Children',
            'total_infants' => 'Total Infants', 'guest_name' => 'Guest Name', 'guest_email' => 'Guest E-mail Id', 'guest_contact' => 'Guest Contact No.',
            'agency_name' => 'Agency Name', 'agent_name' => 'Agent Name', 'agent_email' => 'Agent E-mail Id', 'agent_contact' => 'Agent Contact No.',
            'net_tariff' => 'Net Tariff', 'discount_amount' => 'Discount Amount', 'total_taxes' => 'Total Taxes', 'gross_tariff' => 'Gross Tariff',
            'add_on_tariff' => 'Add On Tariff', 'outlet_tariff' => 'Outlet Tariff', 'advance_amount' => 'Advance Amount', 'net_payable' => 'Net Payable',
            'created_on' => 'Created On', 'created_by' => 'Created By', 'last_modified_on' => 'Last Modified On', 'last_modified_by' => 'Last Modified By',
            'sales_executive' => 'Sales Executive', 'payment_notes' => 'Payment Notes', 'special_instruction' => 'Special Instruction', 'internal_notes' => 'Internal Notes',
            'net_tariff_per_night' => 'Net Tariff Per Night', 'gross_tariff_per_night' => 'Gross Tariff Per Night', 'guest_nationality' => 'Guest Nationality', 'loyalty_card_type' => 'Loyalty Card Type',
        ],
        'daily_sales_reports' => [
            's_no' => 'S.No.', 'reservation_no' => 'Reservation No.', 'check_in' => 'Check In', 'check_out' => 'Check Out',
            'source_type' => 'Source Type', 'status' => 'Status', 'current_status' => 'Current Status', 'booking_type' => 'Booking Type',
            'reference_no' => 'Reference No.', 'pay_at_hotel' => 'Pay at Hotel', 'rooms' => 'Rooms', 'room_numbers' => 'Room Numbers',
            'pax' => 'No. of Pax', 'meal_plans' => 'Meal Plans', 'total_adults' => 'Total Adults', 'total_children' => 'Total Children',
            'total_infants' => 'Total Infants', 'guest_name' => 'Guest Name', 'guest_email_id' => 'Guest E-mail Id', 'guest_contact_no' => 'Guest Contact No.',
            'agency_name' => 'Agency Name', 'agent_name' => 'Agent Name', 'agent_email_id' => 'Agent E-mail Id', 'agent_contact_no' => 'Agent Contact No.',
            'net_tariff' => 'Net Tariff', 'discount_amount' => 'Discount Amount', 'total_taxes' => 'Total Taxes', 'gross_tariff' => 'Gross Tariff',
            'add_on_tariff' => 'Add On Tariff', 'outlet_tariff' => 'Outlet Tariff', 'advance_amount' => 'Advance Amount', 'net_payable' => 'Net Payable',
            'created_on' => 'Created On', 'created_by' => 'Created By', 'last_modified_on' => 'Last Modified On', 'last_modified_by' => 'Last Modified By',
            'sales_executive' => 'Sales Executive', 'payment_notes' => 'Payment Notes', 'special_instruction' => 'Special Instruction', 'internal_notes' => 'Internal Notes',
        ],
        'occupied_rooms_reports' => [
            'room_no' => 'Room No.', 'room_category' => 'Room Category', 'guest_name' => 'Guest Name', 'folio_number' => 'Folio Number',
            'room_status' => 'Room Status', 'arrival_date' => 'Arrival Date', 'departure_date' => 'Departure Date', 'total_adults' => 'Total Adults',
            'total_children' => 'Total Children', 'total_infants' => 'Total Infants', 'meal_plan' => 'Meal Plan', 'rate_plan' => 'Rate Plan',
            'room_cost' => 'Room Cost', 'meal_cost' => 'Meal Cost', 'discount' => 'Discount', 'total_tax' => 'Total Tax',
            'total_price' => 'Total Price', 'city' => 'City', 'country' => 'Country', 'nationality' => 'Nationality',
            'agency_name' => 'Agency Name', 'guest_email_id' => 'Guest E-mail ID', 'guest_phone_no' => 'Guest Phone No.', 'current_status' => 'Current Status',
            'check_in_time' => 'Check-In Time', 'check_out_time' => 'Check-Out Time', 'internal_notes' => 'Internal Notes', 'special_instructions' => 'Special Instructions',
        ],
        'daily_cashier_report' => [
            'receipt_number' => 'Receipt Number', 'guest_name' => 'Guest Name', 'txn_date' => 'Txn Date', 'reference_no_1' => 'Reference No. 1',
            'reference_no_2' => 'Reference No. 2', 'mode' => 'Mode', 'total_amount' => 'Total Amount', 'total_tagged_amount' => 'Total Tagged Amount',
            'total_pg_commission_amount' => 'Total PG Commission Amount', 'paid_by' => 'Paid By', 'party_name' => 'Party Name', 'notes' => 'Notes',
            'collection_source' => 'Collection Source', 'collected_by_staff' => 'Collected By Staff', 'currency' => 'Currency', 'currency_amount' => 'Currency Amount',
        ],
        'checked_out_folios_modification_report' => [
            's_no' => 'S.No.', 'reservation_no' => 'Reservation No.', 'reference_no' => 'Reference No.', 'arrival_date' => 'Arrival Date',
            'departure_date' => 'Departure Date', 'checkout_date' => 'Checkout Date', 'last_modified_date' => 'Last Modified Date', 'created_date' => 'Created Date',
            'modified_by' => 'Modified By', 'room_number' => 'Room Number',
        ],
        'daily_transaction_report' => [
            'folio_no' => 'Folio No.', 'room_no' => 'Room No.', 'guest_name' => 'Guest Name', 'pax' => 'No. of Pax',
            'room_nights' => 'Room Nights', 'package_type' => 'Package Type', 'type_of_reservation' => 'Type of Reservation', 'company' => 'Company',
            'room_rate' => 'Room Rate', 'adjusted_room_rate' => 'Adjusted Room Rate', 'room_only_cost_gross' => 'Room Only Cost (Gross)', 'meal_plan_cost_gross' => 'Meal Plan Cost (Gross)',
            'room_only_cost_net' => 'Room Only Cost (Net)', 'meal_plan_cost_net' => 'Meal Plan Cost (Net)', 'package_net' => 'Package (Net)', 'package_gross' => 'Package (Gross)',
            'date_of_transaction' => 'Date Of Transaction',
        ],
        'daily_housekeeping_report' => [
            'room_no' => 'Room No.', 'room_category' => 'Room Category', 'room_status' => 'Room Status', 'arrival_date' => 'Arrival Date',
            'departure_date' => 'Departure Date', 'time_in' => 'Time-in', 'time_out' => 'Time-out', 'check_out' => 'Check-out',
            'mur' => 'MUR', 'dnd' => 'DND', 'follow_up' => 'Follow-up', 'remarks' => 'Remarks',
            'room_state' => 'Room State', 'pax' => 'Pax', 'adults' => 'Adults', 'children' => 'Children',
            'infant' => 'Infant',
        ],
        'account_receivables_report' => [
            'corporate_id' => 'Corporate ID', 'corporate_account' => 'Corporate Account', 'total_amount' => 'Total Amount', 'total_amount_paid' => 'Total Amount Paid',
            'total_outstanding_amount' => 'Total Outstanding Amount', 'days_0_30_outstanding' => '0-30 Days Outstanding', 'days_31_60_outstanding' => '31-60 Days Outstanding', 'days_61_90_outstanding' => '61-90 Days Outstanding',
            'days_90_plus_outstanding' => '90+ Days Outstanding', 'current_month_outstanding' => 'Current Month Outstanding', 'previous_month_outstanding' => 'Previous Month Outstanding', 'two_months_ago_outstanding' => '2 Months Ago Outstanding',
            'three_months_and_before_outstanding' => '3 Months & Before Outstanding',
        ],
        'addon_report' => [
            'reservation_no' => 'Reservation No.', 'guest_name' => 'Guest Name', 'agency_name' => 'Agency Name', 'addon_name' => 'Addon Name',
            'addon_order_no' => 'Addon Order No.', 'addon_quantity' => 'Addon Quantity', 'invoice_no' => 'Invoice No.', 'addon_creation_date' => 'Addon Creation Date',
            'delivery_date' => 'Delivery Date', 'booking_creation_date' => 'Booking Creation Date', 'outlet' => 'Outlet', 'bill_to' => 'Bill To',
            'total_price' => 'Total Price', 'total_discount' => 'Total Discount', 'total_tax' => 'Total Tax', 'total_amount' => 'Total Amount',
            'room_category' => 'Room Category', 'addon_notes' => 'Addon Notes',
        ],
    ];

    /** Field labels, for the activity log. */
    public const LABELS = [
        'name' => 'Name',
        'email_subject' => 'Email subject',
        'heading' => 'Heading',
        'send_time' => 'Report send time',
        'send_report' => 'Send this report',
        'same_as_internal' => 'Recipients same as internal recipients',
        'recipients' => 'Email recipients',
        'description' => 'Description',
        'hidden_columns' => 'Hidden columns',
    ];

    private const LOG_PREFIX = 'Report settings';

    /**
     * Hidden columns are stored rather than shown ones, so a column added to the
     * catalogue later shows up by default.
     *
     * @return array{name: string, email_subject: string, heading: string, send_time: string, send_report: bool, same_as_internal: bool, recipients: list<string>, description: string, hidden_columns: list<string>}
     */
    public function defaults(string $report): array
    {
        $name = self::REPORTS[$report]['name'];
        $subject = self::REPORTS[$report]['subject'] ?? $name;

        return [
            'hidden_columns' => [],
            'name' => $name,
            'email_subject' => $subject,
            'heading' => $subject,
            'send_time' => '05:30',
            'send_report' => false,
            'same_as_internal' => true,
            'recipients' => [],
            'description' => '',
        ];
    }

    /**
     * Every report's settings plus the property's internal recipients, which a report uses
     * instead of its own list while "same as internal recipients" is ticked.
     *
     * @return array{internal_recipients: list<string>, reports: array<string, array<string, mixed>>}
     */
    public function get(Property $property): array
    {
        $stored = $this->stored($property);
        $reports = [];

        foreach (array_keys(self::REPORTS) as $report) {
            $reports[$report] = [...$this->defaults($report), ...array_intersect_key($stored['reports'][$report] ?? [], $this->defaults($report))];
        }

        return [
            'internal_recipients' => array_values($stored['internal_recipients'] ?? []),
            'reports' => $reports,
        ];
    }

    /**
     * Who the report goes to: the internal recipients, or its own list.
     *
     * @return list<string>
     */
    public function recipientsFor(Property $property, string $report): array
    {
        $settings = $this->get($property);
        $reportSettings = $settings['reports'][$report];

        return $reportSettings['same_as_internal'] ? $settings['internal_recipients'] : $reportSettings['recipients'];
    }

    /**
     * Saves one report's settings and logs each changed field. While the report uses the
     * internal recipients, its recipient list edits the property's internal list.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveReport(Property $property, string $report, array $data, ?User $user = null): void
    {
        $settings = $this->get($property);
        $before = [...$settings['reports'][$report], 'recipients' => $this->recipientsFor($property, $report)];
        $after = [...$settings['reports'][$report], ...array_intersect_key($data, $this->defaults($report))];

        if ($after['same_as_internal']) {
            if (array_key_exists('recipients', $data)) {
                $settings['internal_recipients'] = array_values($after['recipients']);
            }

            $after['recipients'] = $settings['reports'][$report]['recipients'];
        }

        $settings['reports'][$report] = $after;
        $this->store($property, $settings);

        if ($user === null) {
            return;
        }

        $after = [...$after, 'recipients' => $this->recipientsFor($property, $report)];

        foreach ($after as $key => $value) {
            if ($this->display($before[$key]) !== $this->display($value)) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'property_id' => $property->id,
                    'action' => self::LOG_PREFIX.': '.self::REPORTS[$report]['name'].' — '.self::LABELS[$key].' changed from '.$this->display($before[$key]).' to '.$this->display($value),
                    'date' => now(),
                ]);
            }
        }
    }

    /**
     * @return Collection<int, array{action: string, user: string|null, at: string|null}>
     */
    public function logs(Property $property, int $limit = 50): Collection
    {
        return ActivityLog::query()
            ->where('property_id', $property->id)
            ->whereLike('action', self::LOG_PREFIX.'%')
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'action' => substr($log->action, strlen(self::LOG_PREFIX) + 2),
                'user' => $log->user?->name,
                'at' => $log->date?->toIso8601ZuluString(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(Property $property): array
    {
        return app(PropertySettings::class)->json($property, self::KEY) ?? [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function store(Property $property, array $settings): void
    {
        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Reports', 'status' => true])->id;
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($settings)]);
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }
}
