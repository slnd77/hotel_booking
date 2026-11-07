<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue (Manage Account → Roles and Permissions): sections, groups and the
 * permissions in each, keyed by the Spatie permission name. The labels are what the page shows.
 */
final class PermissionCatalog
{
    /** Can do anything: bypasses the permission matrix and every permission check. */
    public const SUPER_ADMIN = 'Super admin';

    /** The original admin role: full access to the panel's pages and actions. */
    public const ADMIN = 'admin';

    /** Signed-up users with no admin panel access. */
    public const USER = 'user';

    /** Roles the account starts with; their permissions are set on the Roles and Permissions page. */
    public const PREDEFINED_ROLES = ['Account Admin', 'Property Admin', 'Reservation Manager', 'Front Office', 'Sales Agent'];

    /** The account owner: like the super admin, opens the pages no permission covers; its ticks still apply. */
    public const ACCOUNT_ADMIN = 'Account Admin';

    /**
     * Front Office and Sales Agent share one matrix.
     *
     * @var list<string>
     */
    private const FRONT_DESK_PERMISSIONS = [
        'reservations.confirm.create_today', 'reservations.confirm.edit_today', 'reservations.confirm.cancel_today',
        'reservations.hold.create_today', 'reservations.hold.edit_today', 'reservations.hold.cancel_today',
        'reservations.enquiry.create_future',
        'notifications.confirm_and_email', 'notifications.message_guest', 'notifications.resend_reservation_email',
        'smart_chart.quick_create', 'smart_chart.block_today',
        'smart_chart.unblock_today', 'smart_chart.unblock_past', 'smart_chart.unblock_future',
        'smart_chart.drag_drop_starting_today', 'smart_chart.add_note', 'smart_chart.expand_categories',
        'smart_chart.view_voucher', 'smart_chart.block_for_booking', 'smart_chart.block_for_maintenance',
        'housekeeping.change_status', 'housekeeping.mark.*', 'housekeeping.board',
        'reservation.change_billing_preference', 'reservation.edit_payment_mode', 'reservation.add_refund',
        'reservation.split', 'reservation.check_in', 'reservation.check_out', 'reservation.undo_check_in',
        'reservation.undo_check_out', 'reservation.checkout_release_room', 'reservation.price_override',
        'reservation.add_payment', 'reservation.print_bill', 'reservation.add_agency', 'reservation.delete_addon',
        'petty_cash.access',
        'pos.apply_discount', 'pos.override_item_price', 'pos.reduce_item_quantity',
        'pos.change_served_by', 'pos.post_transaction', 'pos.edit_transaction',
        'front_office.access', 'front_office.cashier_shift',
        'database.bookings.view',
        'database.payments.cancel', 'database.payments.link_reservation',
        'database.guests.view', 'database.guests.add',
        'database.agents.view', 'database.agents.add', 'database.agents.edit',
        'database.waitlist.view', 'database.waitlist.manage',
    ];

    /**
     * A predefined role's starting permissions: only these.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_DEFAULTS_ONLY = [
        'Reservation Manager' => [
            'reservations.confirm.create_today', 'reservations.confirm.create_future',
            'reservations.confirm.edit_today', 'reservations.confirm.edit_future',
            'reservations.confirm.cancel_today', 'reservations.confirm.cancel_future',
            'reservations.hold.create_today', 'reservations.hold.create_future',
            'reservations.hold.edit_today', 'reservations.hold.edit_future',
            'reservations.hold.cancel_today', 'reservations.hold.cancel_future',
            'reservations.enquiry.create_today', 'reservations.enquiry.create_past', 'reservations.enquiry.create_future',
            'reservations.enquiry.edit_today', 'reservations.enquiry.edit_past', 'reservations.enquiry.edit_future',
            'reservations.enquiry.cancel_today', 'reservations.enquiry.cancel_past', 'reservations.enquiry.cancel_future',
            'notifications.confirm_and_email', 'notifications.message_guest', 'notifications.resend_reservation_email',
            'smart_chart.quick_create', 'smart_chart.block_today', 'smart_chart.block_future',
            'smart_chart.unblock_today', 'smart_chart.unblock_past', 'smart_chart.unblock_future',
            'smart_chart.drag_drop_starting_today', 'smart_chart.add_note', 'smart_chart.view_voucher',
            'smart_chart.block_for_booking', 'smart_chart.block_for_maintenance', 'smart_chart.manage_property',
            'housekeeping.change_status',
            'reservation.add_refund', 'reservation.split', 'reservation.show_card_details', 'reservation.payment_mode_others',
            'reservation.cancel_payment', 'reservation.check_in', 'reservation.check_out', 'reservation.undo_check_in',
            'reservation.undo_check_out', 'reservation.checkout_release_room', 'reservation.price_override',
            'reservation.add_payment', 'reservation.print_bill', 'reservation.add_agency', 'reservation.delete_addon',
            'reservation.waive_stay_charges',
            'petty_cash.edit_expense', 'petty_cash.edit_cash_in', 'petty_cash.edit_cash_out', 'petty_cash.access',
            'pos.apply_discount', 'pos.override_item_price', 'pos.undo_push_to_room', 'pos.reduce_item_quantity',
            'pos.cancel_order', 'pos.change_served_by', 'pos.post_transaction', 'pos.edit_transaction',
            'database.bookings.view', 'database.bookings.download',
            'database.payments.add',
            'database.guests.view', 'database.guests.download',
            'database.agents.view', 'database.agents.download', 'database.agents.add', 'database.agents.add_agency', 'database.agents.edit', 'database.agents.delete',
            'database.waitlist.view', 'database.waitlist.manage',
            'manage_property.report_download_excel', 'manage_property.report_download_pdf',
            'manage_account.expense_management_settings',
        ],
        'Front Office' => self::FRONT_DESK_PERMISSIONS,
        'Sales Agent' => self::FRONT_DESK_PERMISSIONS,
    ];

    /**
     * A predefined role's starting permissions: every permission except these.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_DEFAULTS_EXCEPT = [
        self::ACCOUNT_ADMIN => [
            'reservations.enquiry.cancel_past',
            'invoice.cancel_consolidated',
            'invoice.edit_consolidated_billing',
            'invoice.override_generated_receipt',
            'invoice.override_reprint_consolidated',
            'invoice.reprint_consolidated',
            'invoice.override_wht',
            'reservation.change_billing_preference',
            'smart_chart.expand_categories',
            'smart_chart.manage_property',
            'database.invoices.view_download',
            'database.payments.view_download',
            'database.payments.cancel',
            'database.guests.delete',
            'database.guests.merge',
            'manage_account.tax_exempt_settings',
            'housekeeping.tasks',
        ],
        'Property Admin' => [
            'housekeeping.tasks',
            'invoice.apply_withholding_tax',
            'invoice.cancel_consolidated',
            'invoice.edit_consolidated_billing',
            'invoice.generate_consolidated',
            'invoice.override_generated_receipt',
            'invoice.override_reprint_consolidated',
            'invoice.print_consolidated',
            'invoice.reprint_consolidated',
            'invoice.change_service_charge',
            'invoice.override_wht',
            'reservation.change_billing_preference',
            'reservation.edit_payment_mode',
            'reservation.change_tagged_amount',
            'reservation.add_receipt_number',
            'post_master.undo_check_out',
            'smart_chart.add_note',
            'smart_chart.expand_categories',
            'housekeeping.mark.*',
            'pos.past_delivery_date',
            'pos.undo_settle',
            'database.invoices.view_download',
            'database.payments.view_download',
            'database.payments.cancel',
            'database.guests.delete',
            'database.guests.merge',
            'manage_property.housekeeping_settings',
            'manage_property.post_master_report_add',
            'manage_property.post_master_report_change',
            'manage_property.post_master_report_delete',
            'manage_property.rollover_settings',
            'manage_account.users',
            'manage_account.roles_permissions',
            'manage_account.report_settings',
            'manage_account.smart_chart_settings',
            'manage_account.widget_settings',
            'manage_account.tax_setup_settings',
            'manage_account.tax_exempt_settings',
            'manage_account.api_keys',
            'manage_account.data_privacy',
        ],
    ];

    public const RESERVATION_KINDS = ['confirm' => 'Confirm', 'hold' => 'Hold', 'enquiry' => 'Enquiry'];

    /**
     * section => group => [permission name => label]
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public static function catalogue(): array
    {
        return [
            'Reservations' => [
                'Confirm' => self::reservationMatrix('confirm', 'Reservation'),
                'Hold' => self::reservationMatrix('hold', 'Reservation'),
                'Enquiry' => self::reservationMatrix('enquiry', 'Enquiry'),
                'Notifications' => [
                    'notifications.confirm_and_email' => 'Allow User to Confirm and Email',
                    'notifications.message_guest' => 'Allow User to Message Guest',
                    'notifications.resend_reservation_email' => 'Allow User to Resend Reservation Email',
                ],
                'Invoice' => [
                    'invoice.apply_withholding_tax' => 'Apply Withholding Tax on Invoice',
                    'invoice.cancel_consolidated' => 'Can Cancel Consolidated Invoice',
                    'invoice.edit_consolidated_billing' => 'Can Edit Consolidated Invoice Billing Details',
                    'invoice.generate_consolidated' => 'Can Generate Consolidated Invoice',
                    'invoice.override_generated_receipt' => 'Can Override Generated Invoice Receipt',
                    'invoice.override_reprint_consolidated' => 'Can Override Re-print Consolidated Invoice',
                    'invoice.print_consolidated' => 'Can Print Consolidated Invoice',
                    'invoice.reprint_consolidated' => 'Can Re-print Consolidated Invoice',
                    'invoice.change_service_charge' => 'Can Change Include Service Charge On Invoice',
                    'invoice.override_wht' => 'Can Override WHT On Invoice',
                ],
            ],
            'PMS' => [
                'Smart Chart' => [
                    'smart_chart.quick_create' => 'Access Quick create',
                    'smart_chart.block_today' => 'Block Room for Today',
                    'smart_chart.block_past' => 'Block Room for Past Date',
                    'smart_chart.block_future' => 'Block Room for Future Date',
                    'smart_chart.unblock_today' => 'Unblock Room for Today',
                    'smart_chart.unblock_past' => 'Unblock Room for Past Date',
                    'smart_chart.unblock_future' => 'Unblock Room for Future Date',
                    'smart_chart.drag_drop_starting_today' => 'Drag and Drop Reservation Starting today',
                    'smart_chart.add_note' => 'Add Note',
                    'smart_chart.expand_categories' => 'Can expand room categories',
                    'smart_chart.view_voucher' => 'Allow user to view voucher on smart chart',
                    'smart_chart.block_for_booking' => 'Block Room For Booking',
                    'smart_chart.block_for_maintenance' => 'Block Room For Maintenance',
                    'smart_chart.view_past_voucher' => 'Access to view past voucher on dashboard',
                    'smart_chart.manage_property' => 'Manage property',
                    'housekeeping.change_status' => 'Allow user to change status of room',
                    ...self::housekeepingMarks(),
                ],
                ...(self::housekeepingBoardInstalled() ? ['Housekeeping' => [
                    'housekeeping.board' => 'Open the housekeeping board, create and assign tasks',
                    'housekeeping.inspect' => 'Inspect cleaned rooms',
                    'housekeeping.tasks' => 'Can be given housekeeping tasks (My tasks)',
                ]] : []),
                'Reservation' => [
                    'reservation.change_billing_preference' => 'Allow User to Change Billing Preference',
                    'reservation.edit_payment_mode' => 'Allow User to Edit Payment Mode',
                    'reservation.add_refund' => 'Allow User to add Refund',
                    'reservation.split' => 'Allow User to Split Reservation',
                    'reservation.show_card_details' => 'Show Credit Card details to User',
                    'reservation.payment_mode_others' => 'Allow User to Add Payment Mode as Others',
                    'reservation.cancel_payment' => 'Allow User to Cancel Payment',
                    'reservation.check_in' => 'Check-in Reservation',
                    'reservation.check_out' => 'Check-out Reservation',
                    'reservation.undo_check_in' => 'Undo Check-in Reservation',
                    'reservation.undo_check_out' => 'Undo Check-Out Reservation',
                    'reservation.checkout_release_room' => 'Mark Checkout and Release Room',
                    'reservation.price_override' => 'Allow Price Override',
                    'reservation.override_restrictions' => 'Book Despite Stay Restrictions and Package Conditions',
                    'reservation.upgrade_room' => 'Upgrade a Room (Complimentary or Paid)',
                    'reservation.waive_stay_charges' => 'Waive Early Check-in / Late Check-out Charges',
                    'reservation.add_payment' => 'Allow User To Add Payment',
                    'reservation.print_bill' => 'Allow user to print bill',
                    'reservation.change_tagged_amount' => 'Allow user to change the tagged amount',
                    'reservation.add_agency' => 'Add Agency',
                    'reservation.edit_locked_folio' => 'Change Folios Locked by the Night Audit',
                    'post_master.open' => 'Can open Post Master',
                    'post_master.close' => 'Can close Post Master',
                    'post_master.search' => 'Can search Post Master',
                    'post_master.undo_check_out' => 'Undo check-out Post Master',
                    'reservation.delete_addon' => 'Delete Addon',
                    'reservation.add_receipt_number' => 'Can Add Receipt Number',
                    'reservation.undo_cancel' => 'Undo Cancel Reservation',
                    'reservation.undo_no_show' => 'Undo No Show Reservation',
                    'reservation.undo_checkout_release_room' => 'Undo Checkout and Release Room',
                ],
                'Petty Cash' => [
                    'petty_cash.edit_expense' => 'Allow Edit Expense',
                    'petty_cash.edit_cash_in' => 'Allow Edit Cash-in',
                    'petty_cash.edit_cash_out' => 'Allow Edit Cash-out',
                    'petty_cash.access' => 'Can Access Petty Cash',
                ],
                'POS' => [
                    'pos.apply_discount' => 'Allow user to apply discount on pos',
                    'pos.override_item_price' => 'Can Override Price Of Item',
                    'pos.undo_push_to_room' => 'Can Perform Undo Push To Room',
                    'pos.reduce_item_quantity' => 'Can Reduce Quantity Of Item',
                    'pos.past_delivery_date' => 'Can Set Past Order Delivery Date',
                    'pos.undo_settle' => 'Can Undo Settle POS Order',
                    'pos.cancel_order' => 'Can Cancel POS Order',
                    'pos.change_served_by' => 'Can Change Served By',
                    'pos.post_transaction' => 'Can Post POS Transaction',
                    'pos.edit_transaction' => 'Can Edit POS Transaction',
                ],
                'Front Office' => [
                    'front_office.access' => 'Allow user to access front office',
                    'front_office.rollover' => 'Can view Rollover button in FO',
                    'front_office.cashier_shift' => 'Can open and close own cashier shift',
                    'front_office.cashier_shifts_view_all' => 'Can view all cashier shifts',
                ],
            ],
            'Database' => [
                'Booking Database' => [
                    'database.bookings.view' => 'View Database',
                    'database.bookings.download' => 'Download Database',
                ],
                'Invoice Database' => [
                    'database.invoices.view_download' => 'View & Download Database',
                    'database.invoices.issue_credit_note' => 'Issue Credit Note',
                ],
                'General Ledger' => [
                    'database.general_ledger.view' => 'View Trial Balance and Account Statements',
                ],
                'City Ledger' => [
                    'database.city_ledger.view' => 'View City Ledger',
                    'database.city_ledger.manage' => 'Manage Accounts, Bills and Payments',
                ],
                'Payment Database' => [
                    'database.payments.view_download' => 'View & Download Database',
                    'database.payments.cancel' => 'Cancel Payment',
                    'database.payments.add' => 'Add Payment',
                    'database.payments.link_reservation' => 'Link Reservation to Payment',
                ],
                'Guest Database' => [
                    'database.guests.view' => 'View Database',
                    'database.guests.download' => 'Download Database',
                    'database.guests.delete' => 'Delete Guest',
                    'database.guests.merge' => 'Merge Guest',
                    'database.guests.add' => 'Add Guest',
                    'database.guests.privacy' => 'Export, Erase and Withdraw Consent of Guest Data',
                ],
                'Agent Database' => [
                    'database.agents.view' => 'View Database',
                    'database.agents.download' => 'Download Database',
                    'database.agents.add' => 'Add Agent',
                    'database.agents.add_agency' => 'Can add Agency',
                    'database.agents.edit' => 'Can edit Agent',
                    'database.agents.delete' => 'Can delete and restore Agent',
                ],
                'Waitlist' => [
                    'database.waitlist.view' => 'View Waitlist',
                    'database.waitlist.manage' => 'Add, Cancel and Convert Waitlist Requests',
                ],
            ],
            'Backend Configurations' => [
                'Manage Property' => [
                    'manage_property.property_settings' => 'Access Property Setting',
                    'manage_property.booking_settings' => 'Access Booking Setting',
                    'manage_property.housekeeping_settings' => 'Access Housekeeping Setting',
                    'manage_property.rate_setup' => 'Access Rate Setup',
                    'manage_property.channel_manager' => 'Access Channel Manager',
                    'manage_property.tax_setup' => 'Access Tax Setup',
                    'manage_property.accounts_finance_settings' => 'Access Accounts and Finance Setting',
                    'manage_property.booking_engine_settings' => 'Access Booking Engine Settings',
                    'manage_property.outlet_settings' => 'Access Outlet Settings',
                    'manage_property.notification_settings' => 'Access notification Settings',
                    'manage_property.report_settings' => 'Access Report Settings',
                    'manage_property.payment_gateway_settings' => 'Access Payment Gateway Settings',
                    'manage_property.inventory_grid' => 'Access Inventory Grid',
                    'manage_property.rate_grid' => 'Access Rate Grid',
                    'manage_property.post_master_report_add' => 'Can add Post Master report',
                    'manage_property.post_master_report_change' => 'Can change Post Master report',
                    'manage_property.post_master_report_delete' => 'Can delete Post Master report',
                    'manage_property.report_download_excel' => 'Can Download Excel from Report Settings',
                    'manage_property.report_download_pdf' => 'Can Download PDF from Report Settings',
                    'manage_property.rollover_settings' => 'Can Access Rollover Settings',
                    'manage_property.legal_settings' => 'Access Privacy & Terms Settings',
                ],
                'Manage Account' => [
                    'manage_account.users' => 'Access User Setting',
                    'manage_account.roles_permissions' => 'Access Roles and Permissions',
                    'manage_account.report_settings' => 'Access Report Settings',
                    'manage_account.smart_chart_settings' => 'Access Smart Chart Setting',
                    'manage_account.widget_settings' => 'Access Widget Setting',
                    'manage_account.tax_setup_settings' => 'Access Tax Setup Setting',
                    'manage_account.tax_exempt_settings' => 'Access Tax Exempt Setting',
                    'manage_account.expense_management_settings' => 'Access Expense Management Setting',
                    'manage_account.api_keys' => 'Generate API keys (Profile → Developer)',
                    'manage_account.data_privacy' => 'Access Data & Privacy (retention, full data export)',
                ],
            ],
        ];
    }

    /**
     * Admin routes and the permission they need (any one of a list). Routes not listed need the
     * super admin; null means any user of the admin panel. First matching pattern wins.
     *
     * @var array<string, string|list<string>|null>
     */
    public const ROUTES = [
        // Shared helpers the panel's forms call.
        'admin.locations.*' => null,
        'admin.bookings.roomAvailability' => null,
        'admin.bookings.rateSuggestions' => null,
        'admin.bookings.packageOffers' => null,
        'admin.guests.companies' => null,
        'admin.guests.search' => null,
        'admin.guests.store' => null,
        'admin.guests.update' => null,
        'admin.guests.scanId' => null,
        'admin.databases.agents.search' => null,
        // Today's exchange rates, for the Add Payment form.
        'admin.exchangeRates.current' => null,
        // Each user's own column choices on the tables.
        'admin.listViews.update' => null,
        'admin.exchangeRates.*' => 'manage_property.accounts_finance_settings',

        // Front office.
        'admin.dashboard' => null,
        'admin.smartBookingChartData' => null,
        'admin.frontDesk.*' => 'front_office.access',
        'admin.cashierShifts.index' => ['front_office.cashier_shift', 'front_office.cashier_shifts_view_all'],
        'admin.cashierShifts.show' => ['front_office.cashier_shift', 'front_office.cashier_shifts_view_all'],
        'admin.cashierShifts.*' => 'front_office.cashier_shift',
        'admin.nightAudits.*' => 'front_office.rollover',
        // POS: voiding checks cancel / undo push to room / undo settle by the bill's state.
        'admin.pos.bills.void' => ['pos.cancel_order', 'pos.undo_push_to_room', 'pos.undo_settle'],
        // A bill posted to a room is also opened from the booking's folio by the front desk.
        'admin.pos.bills.show' => ['pos.post_transaction', 'reservation.print_bill'],
        'admin.pos.bills.pdf' => ['pos.post_transaction', 'reservation.print_bill'],
        'admin.pos.bills.print' => ['pos.post_transaction', 'reservation.print_bill'],
        'admin.pos.bills.email' => ['pos.post_transaction', 'reservation.print_bill'],
        'admin.pos.*' => 'pos.post_transaction',

        // Reservations: creating, editing and cancelling are checked by date in the controllers.
        'admin.bookings.create' => null,
        'admin.bookings.store' => null,
        'admin.bookings.show' => null,
        'admin.bookings.summary' => null,
        'admin.bookings.edit' => null,
        'admin.bookings.update' => null,
        'admin.bookings.attachments.show' => null,
        'admin.bookings.updateStatus' => null,
        'admin.bookings.updateCheckInStatus' => null,
        'admin.bookings.rooms.stay' => null,
        'admin.bookings.rooms.checkIn' => 'reservation.check_in',
        'admin.bookings.rooms.checkOut' => 'reservation.check_out',
        'admin.bookings.stayTimeCharges.preview' => ['reservation.check_in', 'reservation.check_out'],
        'admin.bookings.rooms.upgradeOptions' => 'reservation.upgrade_room',
        'admin.bookings.rooms.upgrade' => 'reservation.upgrade_room',
        'admin.bookings.split' => 'reservation.split',
        'admin.bookings.rooms.datesPreview' => null,
        'admin.bookings.rooms.dates' => null,
        'admin.bookings.print' => 'reservation.print_bill',
        'admin.bookings.printPdf' => 'reservation.print_bill',
        'admin.bookings.folioTransfers.store' => 'reservation.change_billing_preference',
        'admin.bookings.folios.*' => 'reservation.change_billing_preference',
        'admin.bookings.guestLedger.*' => 'reservation.change_billing_preference',
        'admin.bookings.splitPayments.store' => 'reservation.change_tagged_amount',
        'admin.bookings.paymentLinks.*' => 'reservation.add_payment',
        'admin.bookings.move' => null,
        'admin.bookings.addons.store' => null,
        'admin.bookings.addons.destroy' => 'reservation.delete_addon',
        'admin.bookings.webCheckin.link' => null,
        'admin.bookings.webCheckin.send' => null,
        // The guest's ID document and signature: whoever checks guests in.
        'admin.bookings.webCheckin.document' => ['reservation.check_in', 'reservation.print_bill'],
        'admin.bookings.webCheckin.signature' => ['reservation.check_in', 'reservation.print_bill'],

        // Smart Chart: blocking, unblocking and room status are checked in the controllers.
        'admin.roomBlocks.*' => null,
        'admin.chartNotes.*' => null,
        'admin.rooms.housekeeping' => null,

        // Housekeeping board (when installed): attendants check the task is theirs in the controller.
        'admin.housekeeping.tasks.*' => ['housekeeping.tasks', 'housekeeping.board'],
        'admin.housekeeping.inspect' => 'housekeeping.inspect',
        'admin.housekeeping.*' => 'housekeeping.board',

        // Databases.
        'admin.databases.bookings.index' => 'database.bookings.view',
        'admin.databases.enquiries.index' => 'database.bookings.view',
        'admin.databases.bookings.download' => 'database.bookings.download',
        'admin.databases.invoices.creditNotes.store' => 'database.invoices.issue_credit_note',
        'admin.generalLedger.index' => ['database.general_ledger.view', 'manage_property.accounts_finance_settings'],
        'admin.generalLedger.show' => ['database.general_ledger.view', 'manage_property.accounts_finance_settings'],
        'admin.generalLedger.*' => 'manage_property.accounts_finance_settings',
        'admin.databases.cityLedger.index' => 'database.city_ledger.view',
        'admin.databases.cityLedger.show' => 'database.city_ledger.view',
        'admin.databases.cityLedger.statement' => 'database.city_ledger.view',
        'admin.databases.cityLedger.*' => 'database.city_ledger.manage',
        'admin.databases.invoices.*' => 'database.invoices.view_download',
        'admin.databases.folios.*' => 'database.invoices.view_download',
        'admin.databases.payments.index' => 'database.payments.view_download',
        'admin.databases.payments.download' => 'database.payments.view_download',
        'admin.databases.payments.store' => ['database.payments.add', 'reservation.add_payment'],
        'admin.databases.payments.cancel' => ['database.payments.cancel', 'reservation.cancel_payment'],
        'admin.databases.guests.index' => 'database.guests.view',
        'admin.databases.guests.download' => 'database.guests.download',
        'admin.databases.guests.uploadTemplate' => 'database.guests.add',
        'admin.databases.guests.upload' => 'database.guests.add',
        'admin.databases.guests.privacy.*' => 'database.guests.privacy',
        'admin.databases.guests.merge.*' => 'database.guests.merge',
        'admin.databases.agents.index' => 'database.agents.view',
        'admin.databases.agents.download' => 'database.agents.download',
        'admin.databases.agents.store' => 'database.agents.add',
        'admin.databases.agents.update' => 'database.agents.edit',
        'admin.databases.agents.destroy' => 'database.agents.delete',
        'admin.databases.agents.restore' => 'database.agents.delete',
        'admin.databases.waitlist.index' => 'database.waitlist.view',
        'admin.databases.waitlist.store' => 'database.waitlist.manage',
        'admin.databases.waitlist.cancel' => 'database.waitlist.manage',
        'admin.databases.waitlist.convert' => 'database.waitlist.manage',
        'admin.databases.waitlist.refresh' => 'database.waitlist.manage',

        // Manage property.
        'admin.properties.*' => 'manage_property.property_settings',
        'admin.property-groups.*' => 'manage_property.property_settings',
        'admin.bookingSettings.*' => 'manage_property.booking_settings',
        'admin.rateSettings.*' => 'manage_property.rate_setup',
        'admin.rateRestrictions.*' => 'manage_property.rate_setup',
        'admin.channelManager.*' => 'manage_property.channel_manager',
        'admin.agentSettings.*' => 'manage_property.rate_setup',
        'admin.taxSettings.*' => 'manage_property.tax_setup',
        'admin.invoiceSettings.*' => 'manage_property.accounts_finance_settings',
        'admin.bookingEngineSettings.*' => 'manage_property.booking_engine_settings',
        'admin.outlets.*' => 'manage_property.outlet_settings',
        'admin.nightAuditSettings.*' => 'manage_property.rollover_settings',
        'admin.legalSettings.*' => 'manage_property.legal_settings',
        'admin.reports.download' => ['manage_property.report_download_excel', 'manage_property.report_download_pdf'],
        // Opening Reports to download is enough with a download permission; changing them needs Report Settings.
        'admin.reports.index' => ['manage_property.report_settings', 'manage_account.report_settings', 'manage_property.report_download_excel', 'manage_property.report_download_pdf'],
        'admin.reports.edit' => ['manage_property.report_settings', 'manage_account.report_settings', 'manage_property.report_download_excel', 'manage_property.report_download_pdf'],
        'admin.reports.*' => ['manage_property.report_settings', 'manage_account.report_settings'],
        'admin.applicationSettings.notificationChannels.*' => 'manage_property.notification_settings',
        'admin.applicationSettings.guestEmails.*' => 'manage_property.notification_settings',
        'admin.applicationSettings.edit' => ['manage_account.smart_chart_settings', 'manage_account.widget_settings', 'manage_property.notification_settings'],
        'admin.applicationSettings.update' => ['manage_account.smart_chart_settings', 'manage_account.widget_settings'],

        // Manage account.
        'admin.users.*' => 'manage_account.users',
        // Deleting the account is checked again for the account owner.
        'admin.dataPrivacy.*' => 'manage_account.data_privacy',
        'admin.roles.*' => 'manage_account.roles_permissions',
    ];

    /**
     * The permission a route needs: false when it isn't listed (super admin only).
     *
     * @return string|list<string>|false|null
     */
    public static function forRoute(string $routeName): string|array|false|null
    {
        foreach (self::ROUTES as $pattern => $permission) {
            if (Str::is($pattern, $routeName)) {
                return $permission;
            }
        }

        return false;
    }

    /**
     * Every permission name, in catalogue order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::catalogue() as $groups) {
            foreach ($groups as $permissions) {
                array_push($names, ...array_keys($permissions));
            }
        }

        return $names;
    }

    /**
     * Only Super admin, Admin and Account Admin give users roles; other roles with access to
     * Users manage names, e-mails and passwords only.
     */
    public static function canAssignRoles(User $grantor): bool
    {
        return $grantor->hasAnyRole(self::ownerRoles());
    }

    /**
     * Roles $grantor may give a user, value => label: every role for Super admin and Admin,
     * all but those two for Account Admin, none for anyone else.
     *
     * @return array<string, string>
     */
    public static function assignableRoles(User $grantor): array
    {
        if (! self::canAssignRoles($grantor)) {
            return [];
        }

        return [
            ...($grantor->hasAnyRole(self::fullAccessRoles()) ? [self::SUPER_ADMIN => 'Super admin (bypasses all permissions)', self::ADMIN => 'Admin (full access)'] : []),
            ...array_combine(self::PREDEFINED_ROLES, self::PREDEFINED_ROLES),
            ...array_combine(self::customRoles(), self::customRoles()),
            self::USER => 'User (no admin panel)',
        ];
    }

    /**
     * $grantor may edit or delete $user: one who gives roles, when they could have given every
     * role $user has; anyone else, when $user is not an owner (Super admin, Admin, Account Admin).
     */
    public static function canManageUser(User $grantor, User $user): bool
    {
        if (! self::canAssignRoles($grantor)) {
            return ! $user->hasAnyRole(self::ownerRoles());
        }

        return $user->roles->pluck('name')->diff(array_keys(self::assignableRoles($grantor)))->isEmpty();
    }

    /**
     * A predefined role's starting permissions.
     *
     * @return list<string>
     */
    public static function defaultsFor(string $role): array
    {
        // Entries may end in ".*": every permission under that prefix (the housekeeping marks).
        $listed = fn (string $name, array $patterns): bool => Str::is($patterns, $name);

        if (array_key_exists($role, self::ROLE_DEFAULTS_ONLY)) {
            return array_values(array_filter(self::names(), fn (string $name): bool => $listed($name, self::ROLE_DEFAULTS_ONLY[$role])));
        }

        if (! array_key_exists($role, self::ROLE_DEFAULTS_EXCEPT)) {
            return [];
        }

        return array_values(array_filter(self::names(), fn (string $name): bool => ! $listed($name, self::ROLE_DEFAULTS_EXCEPT[$role])));
    }

    /**
     * Whether the user may make API keys under Profile → Developer.
     */
    public static function canManageApiKeys(User $user): bool
    {
        if ($user->hasAnyRole(self::fullAccessRoles())) {
            return true;
        }

        self::ensurePermissions();

        return $user->checkPermissionTo('manage_account.api_keys');
    }

    /**
     * Roles that pass every page and action check of the matrix.
     *
     * @return list<string>
     */
    public static function fullAccessRoles(): array
    {
        return [self::SUPER_ADMIN, self::ADMIN];
    }

    /**
     * Roles that open the pages no permission covers (account-owner pages).
     *
     * @return list<string>
     */
    public static function ownerRoles(): array
    {
        return [...self::fullAccessRoles(), self::ACCOUNT_ADMIN];
    }

    /**
     * Roles the application defines: they can't be renamed, edited or deleted on the Roles page.
     *
     * @return list<string>
     */
    public static function systemRoles(): array
    {
        return [...self::fullAccessRoles(), ...self::PREDEFINED_ROLES, self::USER];
    }

    public static function isCustomRole(string $name): bool
    {
        return ! in_array($name, self::systemRoles(), true);
    }

    /**
     * User defined roles, by name.
     *
     * @return list<string>
     */
    public static function customRoles(): array
    {
        return Role::query()->whereNotIn('name', self::systemRoles())->orderBy('name')->pluck('name')->all();
    }

    /**
     * Every role but the plain "user" opens the admin panel: the full-access, predefined and user defined roles.
     */
    public static function canEnterPanel(User $user): bool
    {
        return $user->roles->contains(fn (Role $role): bool => $role->name !== self::USER);
    }

    /**
     * A package provides the housekeeping board and it is switched on (its routes exist).
     */
    private static function housekeepingBoardInstalled(): bool
    {
        return Route::has('admin.housekeeping.index');
    }

    /**
     * The permission for marking a room with this housekeeping status.
     */
    public static function housekeepingMark(string $status): string
    {
        return 'housekeeping.mark.'.Str::slug($status, '_');
    }

    /**
     * One "User can mark room as …" permission per housekeeping status in Booking Settings →
     * Housekeeping, across the account's properties.
     *
     * @return array<string, string>
     */
    private static function housekeepingMarks(): array
    {
        $marks = [];

        foreach (app(Housekeeping::class)->statusNamesAcrossProperties() as $status) {
            $marks[self::housekeepingMark($status)] ??= "User can mark room as {$status}";
        }

        return $marks;
    }

    /**
     * Create the permission rows the catalogue lists but the database lacks yet (a housekeeping
     * status added since).
     */
    public static function ensurePermissions(): void
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_diff(self::names(), $existing);

        foreach ($missing as $name) {
            Permission::findOrCreate($name, 'web');
        }

        if ($missing !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * "reservations.confirm.create_today" style names for one reservation kind.
     *
     * @return array<string, string>
     */
    private static function reservationMatrix(string $kind, string $noun): array
    {
        $permissions = [];

        foreach (['create' => 'Create', 'edit' => 'Edit', 'cancel' => 'Cancel'] as $action => $verb) {
            foreach (['today' => 'Today', 'past' => 'Past Date', 'future' => 'Future Date'] as $when => $whenLabel) {
                $permissions["reservations.{$kind}.{$action}_{$when}"] = "{$verb} {$noun} for {$whenLabel}";
            }
        }

        return $permissions;
    }
}
