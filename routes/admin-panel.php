<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AgentSettingController;
use App\Http\Controllers\Admin\ApplicationSettingController;
use App\Http\Controllers\Admin\BookingAddonController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\BookingEngineSettingController;
use App\Http\Controllers\Admin\BookingFolioController;
use App\Http\Controllers\Admin\BookingPrintController;
use App\Http\Controllers\Admin\BookingSettingController;
use App\Http\Controllers\Admin\BookingWebCheckinController;
use App\Http\Controllers\Admin\CashierShiftController;
use App\Http\Controllers\Admin\ChannelManagerController;
use App\Http\Controllers\Admin\ChartNoteController;
use App\Http\Controllers\Admin\CityLedgerController;
use App\Http\Controllers\Admin\CreditNoteController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\DataPrivacyController;
use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Controllers\Admin\FolioDatabaseController;
use App\Http\Controllers\Admin\FolioTransferController;
use App\Http\Controllers\Admin\FrontDeskController;
use App\Http\Controllers\Admin\GeneralLedgerController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\GuestIdScanController;
use App\Http\Controllers\Admin\GuestLedgerController;
use App\Http\Controllers\Admin\GuestMergeController;
use App\Http\Controllers\Admin\GuestPrivacyController;
use App\Http\Controllers\Admin\HousekeepingController;
use App\Http\Controllers\Admin\InvoiceSettingController;
use App\Http\Controllers\Admin\LegalSettingController;
use App\Http\Controllers\Admin\ListViewController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MoveBookingController;
use App\Http\Controllers\Admin\NightAuditController;
use App\Http\Controllers\Admin\NightAuditSettingController;
use App\Http\Controllers\Admin\NotificationChannelController;
use App\Http\Controllers\Admin\OutletController;
use App\Http\Controllers\Admin\OutletDisplayCategoryController;
use App\Http\Controllers\Admin\OutletItemController;
use App\Http\Controllers\Admin\OutletPricePlanController;
use App\Http\Controllers\Admin\OutletTableController;
use App\Http\Controllers\Admin\OutletTaxRateController;
use App\Http\Controllers\Admin\PaymentLinkController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\PropertyAddonController;
use App\Http\Controllers\Admin\PropertyAmenityController;
use App\Http\Controllers\Admin\PropertyBrandingController;
use App\Http\Controllers\Admin\PropertyCancellationRuleController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\PropertyGroupsController;
use App\Http\Controllers\Admin\PropertyHolidayController;
use App\Http\Controllers\Admin\PropertyRoomCategoryController;
use App\Http\Controllers\Admin\PropertyWebCheckinController;
use App\Http\Controllers\Admin\QzTrayController;
use App\Http\Controllers\Admin\RateRestrictionController;
use App\Http\Controllers\Admin\RateSettingController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoomBlockController;
use App\Http\Controllers\Admin\RoomCategoryAmenityController;
use App\Http\Controllers\Admin\RoomCategoryBedTypeController;
use App\Http\Controllers\Admin\RoomCategoryImageController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\RoomDatesController;
use App\Http\Controllers\Admin\RoomStayController;
use App\Http\Controllers\Admin\RoomUpgradeController;
use App\Http\Controllers\Admin\SplitPaymentController;
use App\Http\Controllers\Admin\StayTimeChargeController;
use App\Http\Controllers\Admin\TaxSettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WaitlistController;
use App\Services\ApplicationSettings;
use Illuminate\Support\Facades\Route;

/*
 * The admin panel's routes. routes/admin.php registers them for the web (session login,
 * /admin, admin.*); routes/api.php registers them again for the mobile app (bearer token,
 * /api/v1/panel, api.v1.panel.*), where each page answers its Inertia props as JSON.
 */
return function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('smart-booking-chart-data', [DashboardController::class, 'chartData'])->name('smartBookingChartData');
    Route::get('front-desk', [FrontDeskController::class, 'index'])->name('frontDesk.index');
    Route::get('front-desk/{property}', [FrontDeskController::class, 'show'])->name('frontDesk.show');
    Route::get('cashier-shifts', [CashierShiftController::class, 'index'])->name('cashierShifts.index');
    Route::get('cashier-shifts/{property}', [CashierShiftController::class, 'show'])->name('cashierShifts.show');
    Route::post('cashier-shifts/{property}/open', [CashierShiftController::class, 'open'])->name('cashierShifts.open');
    Route::post('cashier-shifts/{property}/close', [CashierShiftController::class, 'close'])->name('cashierShifts.close');

    Route::get('application-settings', [ApplicationSettingController::class, 'edit'])->name('applicationSettings.edit');
    Route::post('application-settings/block-reasons', [ApplicationSettingController::class, 'storeBlockReason'])->name('applicationSettings.blockReasons.store');
    Route::put('application-settings/block-reasons/order', [ApplicationSettingController::class, 'reorderBlockReasons'])->name('applicationSettings.blockReasons.reorder');
    Route::put('application-settings/block-reasons/{blockReason}', [ApplicationSettingController::class, 'updateBlockReason'])->name('applicationSettings.blockReasons.update');
    Route::put('application-settings/notification-channels/email', [NotificationChannelController::class, 'updateEmail'])->name('applicationSettings.notificationChannels.email.update');
    Route::put('application-settings/guest-emails', [NotificationChannelController::class, 'updateGuestEmails'])->name('applicationSettings.guestEmails.update');
    Route::post('application-settings/notification-channels/email/test', [NotificationChannelController::class, 'sendTestEmail'])->name('applicationSettings.notificationChannels.email.test');
    Route::put('application-settings/notification-channels/{channel}/status', [NotificationChannelController::class, 'updateStatus'])
        ->whereIn('channel', array_keys(ApplicationSettings::NOTIFICATION_CHANNELS))
        ->name('applicationSettings.notificationChannels.status');
    Route::put('application-settings/{section}', [ApplicationSettingController::class, 'update'])
        ->whereIn('section', ['reservation', 'guest', 'widget', 'day_posting', 'smart_chart'])
        ->name('applicationSettings.update');
    Route::get('data-privacy', [DataPrivacyController::class, 'edit'])->name('dataPrivacy.edit');
    Route::put('data-privacy/retention', [DataPrivacyController::class, 'updateRetention'])->name('dataPrivacy.retention.update');
    Route::post('data-privacy/exports', [DataPrivacyController::class, 'storeExport'])->middleware('throttle:5,60')->name('dataPrivacy.exports.store');
    Route::get('data-privacy/exports/{accountDataRequest}', [DataPrivacyController::class, 'downloadExport'])->name('dataPrivacy.exports.download');
    Route::post('data-privacy/deletion', [DataPrivacyController::class, 'scheduleDeletion'])->name('dataPrivacy.deletion.store');
    Route::delete('data-privacy/deletion/{accountDataRequest}', [DataPrivacyController::class, 'cancelDeletion'])->name('dataPrivacy.deletion.destroy');
    Route::resource('users', UserController::class)->except('show');
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::get('locations/states', [LocationController::class, 'states'])->name('locations.states');
    Route::get('locations/cities', [LocationController::class, 'cities'])->name('locations.cities');

    Route::resource('property-groups', PropertyGroupsController::class)
        ->parameters(['property-groups' => 'propertyGroup'])
        ->except('destroy');
    Route::resource('properties', PropertyController::class)->except('destroy');

    Route::get('booking-settings', [BookingSettingController::class, 'index'])->name('bookingSettings.index');
    Route::get('booking-settings/{property}', [BookingSettingController::class, 'edit'])->name('bookingSettings.edit');
    Route::put('booking-settings/{property}', [BookingSettingController::class, 'update'])->name('bookingSettings.update');

    Route::get('rate-settings', [RateSettingController::class, 'index'])->name('rateSettings.index');
    Route::get('rate-settings/{property}', [RateSettingController::class, 'edit'])->name('rateSettings.edit');
    Route::get('rate-settings/{property}/restrictions', [RateRestrictionController::class, 'edit'])->name('rateRestrictions.edit');
    Route::put('rate-settings/{property}/restrictions', [RateRestrictionController::class, 'update'])->name('rateRestrictions.update');
    Route::put('rate-settings/{property}', [RateSettingController::class, 'update'])->name('rateSettings.update');
    Route::get('channel-manager', [ChannelManagerController::class, 'index'])->name('channelManager.index');
    Route::get('channel-manager/{property}', [ChannelManagerController::class, 'edit'])->name('channelManager.edit');
    Route::post('channel-manager/{property}/connect', [ChannelManagerController::class, 'connect'])->name('channelManager.connect');
    Route::put('channel-manager/{property}', [ChannelManagerController::class, 'update'])->name('channelManager.update');
    Route::put('channel-manager/{property}/mappings', [ChannelManagerController::class, 'saveMappings'])->name('channelManager.mappings');
    Route::post('channel-manager/{property}/create-rooms-and-rates', [ChannelManagerController::class, 'createRoomsAndRates'])->name('channelManager.createRoomsAndRates');
    Route::post('channel-manager/{property}/sync', [ChannelManagerController::class, 'sync'])->name('channelManager.sync');
    Route::post('channel-manager/{property}/pull', [ChannelManagerController::class, 'pull'])->name('channelManager.pull');
    Route::get('channel-manager/{property}/channels', [ChannelManagerController::class, 'channels'])->name('channelManager.channels');
    Route::delete('channel-manager/{property}', [ChannelManagerController::class, 'destroy'])->name('channelManager.destroy');

    Route::get('tax-settings', [TaxSettingController::class, 'index'])->name('taxSettings.index');
    Route::get('tax-settings/{property}', [TaxSettingController::class, 'edit'])->name('taxSettings.edit');
    Route::put('tax-settings/{property}', [TaxSettingController::class, 'update'])->name('taxSettings.update');

    Route::get('legal-settings', [LegalSettingController::class, 'index'])->name('legalSettings.index');
    Route::get('legal-settings/{property}', [LegalSettingController::class, 'edit'])->name('legalSettings.edit');
    Route::put('legal-settings/{property}', [LegalSettingController::class, 'update'])->name('legalSettings.update');

    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchangeRates.index');
    Route::get('exchange-rates/{property}', [ExchangeRateController::class, 'edit'])->name('exchangeRates.edit');
    Route::post('exchange-rates/{property}', [ExchangeRateController::class, 'store'])->name('exchangeRates.store');
    Route::get('exchange-rates/{property}/current', [ExchangeRateController::class, 'current'])->name('exchangeRates.current');
    Route::put('list-views/{table}', [ListViewController::class, 'update'])->name('listViews.update');
    Route::get('invoice-settings', [InvoiceSettingController::class, 'index'])->name('invoiceSettings.index');
    Route::get('invoice-settings/{property}', [InvoiceSettingController::class, 'edit'])->name('invoiceSettings.edit');
    Route::put('invoice-settings/{property}', [InvoiceSettingController::class, 'update'])->name('invoiceSettings.update');
    Route::post('invoice-settings/{property}/images', [InvoiceSettingController::class, 'storeImage'])->name('invoiceSettings.images.store');
    Route::get('invoice-settings/{property}/images/{type}', [InvoiceSettingController::class, 'showImage'])->name('invoiceSettings.images.show');
    Route::delete('invoice-settings/{property}/images/{type}', [InvoiceSettingController::class, 'destroyImage'])->name('invoiceSettings.images.destroy');

    Route::get('agent-settings', [AgentSettingController::class, 'index'])->name('agentSettings.index');
    Route::get('agent-settings/{property}', [AgentSettingController::class, 'edit'])->name('agentSettings.edit');
    Route::post('agent-settings/{property}/rate-codes', [AgentSettingController::class, 'store'])->name('agentSettings.rateCodes.store');
    Route::put('agent-settings/{property}/rate-codes/{rateCode}', [AgentSettingController::class, 'update'])->name('agentSettings.rateCodes.update');
    Route::delete('agent-settings/{property}/rate-codes/{rateCode}', [AgentSettingController::class, 'destroy'])->name('agentSettings.rateCodes.destroy');

    Route::get('pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('pos-qz/certificate', [QzTrayController::class, 'certificate'])->name('pos.qz.certificate');
    Route::post('pos-qz/sign', [QzTrayController::class, 'sign'])->middleware('throttle:120,1')->name('pos.qz.sign');
    Route::scopeBindings()->group(function (): void {
        Route::get('pos/{property}', [PosController::class, 'show'])->name('pos.show');
        Route::post('pos/{property}/outlets/{outlet}/bills', [PosController::class, 'store'])->name('pos.bills.store');
        Route::put('pos/{property}/bills/{outletBill}', [PosController::class, 'update'])->name('pos.bills.update');
        Route::post('pos/{property}/bills/{outletBill}/post-to-room', [PosController::class, 'postToRoom'])->name('pos.bills.postToRoom');
        Route::post('pos/{property}/bills/{outletBill}/settle', [PosController::class, 'settle'])->name('pos.bills.settle');
        Route::post('pos/{property}/bills/{outletBill}/void', [PosController::class, 'void'])->name('pos.bills.void');
        Route::post('pos/{property}/bills/{outletBill}/split', [PosController::class, 'split'])->name('pos.bills.split');
        Route::post('pos/{property}/bills/{outletBill}/table', [PosController::class, 'moveTable'])->name('pos.bills.moveTable');
        Route::post('pos/{property}/guest-orders/{outletGuestOrder}/accept', [PosController::class, 'acceptGuestOrder'])->name('pos.guestOrders.accept');
        Route::post('pos/{property}/guest-orders/{outletGuestOrder}/reject', [PosController::class, 'rejectGuestOrder'])->name('pos.guestOrders.reject');
        Route::post('pos/{property}/bills/{outletBill}/kots', [PosController::class, 'sendKot'])->name('pos.bills.kots.store');
        Route::get('pos/{property}/bills/{outletBill}', [PosController::class, 'showBill'])->name('pos.bills.show');
        Route::get('pos/{property}/bills/{outletBill}/pdf', [PosController::class, 'billPdf'])->name('pos.bills.pdf');
        Route::post('pos/{property}/bills/{outletBill}/email', [PosController::class, 'emailBill'])->middleware('throttle:20,1')->name('pos.bills.email');
        Route::get('pos/{property}/bills/{outletBill}/print', [PosController::class, 'printBill'])->name('pos.bills.print');
        Route::post('pos/{property}/bills/{outletBill}/print', [PosController::class, 'sendBillToPrinter'])->name('pos.bills.sendToPrinter');
        Route::get('pos/{property}/kots/{outletKot}/print', [PosController::class, 'printKot'])->name('pos.kots.print');
        Route::post('pos/{property}/kots/{outletKot}/print', [PosController::class, 'sendKotToPrinter'])->name('pos.kots.sendToPrinter');
        Route::get('pos/{property}/report', [PosController::class, 'report'])->name('pos.report');
        Route::get('pos/{property}/outlets/{outlet}/drawer', [PosController::class, 'drawerKick'])->name('pos.drawer.escpos');
        Route::post('pos/{property}/outlets/{outlet}/drawer', [PosController::class, 'openDrawer'])->name('pos.drawer.open');
    });

    Route::get('night-audit-settings', [NightAuditSettingController::class, 'index'])->name('nightAuditSettings.index');
    Route::get('night-audit-settings/{property}', [NightAuditSettingController::class, 'edit'])->name('nightAuditSettings.edit');
    Route::put('night-audit-settings/{property}', [NightAuditSettingController::class, 'update'])->name('nightAuditSettings.update');

    Route::get('outlets', [OutletController::class, 'index'])->name('outlets.index');
    Route::scopeBindings()->group(function (): void {
        Route::get('outlets/{property}', [OutletController::class, 'edit'])->name('outlets.edit');
        Route::post('outlets/{property}', [OutletController::class, 'store'])->name('outlets.store');
        Route::get('outlets/{property}/{outlet}', [OutletController::class, 'show'])->name('outlets.show');
        Route::put('outlets/{property}/{outlet}', [OutletController::class, 'update'])->name('outlets.update');
        Route::delete('outlets/{property}/{outlet}', [OutletController::class, 'destroy'])->name('outlets.destroy');
        Route::get('outlets/{property}/{outlet}/qr', [OutletController::class, 'qr'])->name('outlets.qr');
        Route::post('outlets/{property}/{outlet}/qr', [OutletController::class, 'renewQr'])->name('outlets.renewQr');
        Route::post('outlets/{property}/{outlet}/tables', [OutletTableController::class, 'store'])->name('outlets.tables.store');
        Route::put('outlets/{property}/{outlet}/tables-layout', [OutletTableController::class, 'layout'])->name('outlets.tables.layout');
        Route::put('outlets/{property}/{outlet}/tables-settings', [OutletTableController::class, 'settings'])->name('outlets.tables.settings');
        Route::put('outlets/{property}/{outlet}/tables/{table}', [OutletTableController::class, 'update'])->name('outlets.tables.update');
        Route::delete('outlets/{property}/{outlet}/tables/{table}', [OutletTableController::class, 'destroy'])->name('outlets.tables.destroy');
        Route::get('outlets/{property}/{outlet}/tables/{table}/qr', [OutletTableController::class, 'qr'])->name('outlets.tables.qr');
        Route::post('outlets/{property}/{outlet}/tables/{table}/qr', [OutletTableController::class, 'renewQr'])->name('outlets.tables.renewQr');
        Route::post('outlets/{property}/{outlet}/display-categories', [OutletDisplayCategoryController::class, 'store'])->name('outlets.displayCategories.store');
        Route::put('outlets/{property}/{outlet}/display-categories/{displayCategory}', [OutletDisplayCategoryController::class, 'update'])->name('outlets.displayCategories.update');
        Route::delete('outlets/{property}/{outlet}/display-categories/{displayCategory}', [OutletDisplayCategoryController::class, 'destroy'])->name('outlets.displayCategories.destroy');
        Route::post('outlets/{property}/{outlet}/items', [OutletItemController::class, 'store'])->name('outlets.items.store');
        Route::put('outlets/{property}/{outlet}/items', [OutletItemController::class, 'updateStatuses'])->name('outlets.items.statuses');
        Route::put('outlets/{property}/{outlet}/items/{item}', [OutletItemController::class, 'update'])->name('outlets.items.update');
        Route::delete('outlets/{property}/{outlet}/items/{item}', [OutletItemController::class, 'destroy'])->name('outlets.items.destroy');
        Route::post('outlets/{property}/{outlet}/tax-rates', [OutletTaxRateController::class, 'store'])->name('outlets.taxRates.store');
        Route::put('outlets/{property}/{outlet}/tax-rates/{taxRate}', [OutletTaxRateController::class, 'update'])->name('outlets.taxRates.update');
        Route::delete('outlets/{property}/{outlet}/tax-rates/{taxRate}', [OutletTaxRateController::class, 'destroy'])->name('outlets.taxRates.destroy');
        Route::post('outlets/{property}/{outlet}/price-plans', [OutletPricePlanController::class, 'store'])->name('outlets.pricePlans.store');
        Route::put('outlets/{property}/{outlet}/price-plans/{pricePlan}', [OutletPricePlanController::class, 'update'])->name('outlets.pricePlans.update');
        Route::delete('outlets/{property}/{outlet}/price-plans/{pricePlan}', [OutletPricePlanController::class, 'destroy'])->name('outlets.pricePlans.destroy');
        Route::put('outlets/{property}/{outlet}/price-plans/{pricePlan}/prices', [OutletPricePlanController::class, 'updatePrices'])->name('outlets.pricePlans.prices');
    });

    Route::get('night-audits', [NightAuditController::class, 'index'])->name('nightAudits.index');
    Route::get('night-audits/{property}', [NightAuditController::class, 'edit'])->name('nightAudits.edit');
    Route::post('night-audits/{property}', [NightAuditController::class, 'store'])->name('nightAudits.store');
    Route::post('night-audits/{property}/reconciliation', [NightAuditController::class, 'reconcile'])->name('nightAudits.reconcile');
    Route::post('night-audits/{property}/invoices', [NightAuditController::class, 'fixInvoices'])->name('nightAudits.invoices');
    Route::get('night-audits/{property}/{nightAudit}', [NightAuditController::class, 'show'])->name('nightAudits.show');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{property}', [ReportController::class, 'edit'])->name('reports.edit');
    Route::put('reports/{property}/{report}', [ReportController::class, 'update'])->name('reports.update');
    Route::put('reports/{property}/{report}/status', [ReportController::class, 'status'])->name('reports.status');
    Route::put('reports/{property}/{report}/columns', [ReportController::class, 'columns'])->name('reports.columns');
    Route::get('reports/{property}/{report}/download', [ReportController::class, 'download'])->name('reports.download');
    Route::post('reports/{property}/{report}/send', [ReportController::class, 'send'])->middleware('throttle:10,1')->name('reports.send');

    Route::get('properties/{property}/bookings/create', [BookingController::class, 'create'])->name('bookings.create');
    Route::get('properties/{property}/bookings/room-availability', [BookingController::class, 'roomAvailability'])->name('bookings.roomAvailability');
    Route::get('properties/{property}/bookings/rate-suggestions', [BookingController::class, 'rateSuggestions'])->name('bookings.rateSuggestions');
    Route::get('properties/{property}/bookings/package-offers', [BookingController::class, 'packageOffers'])->name('bookings.packageOffers');
    Route::post('properties/{property}/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('properties/{property}/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('properties/{property}/bookings/{booking}/print/{document}', [BookingPrintController::class, 'show'])->name('bookings.print');
    Route::get('properties/{property}/bookings/{booking}/print/{document}/pdf', [BookingPrintController::class, 'download'])->name('bookings.printPdf');
    Route::get('properties/{property}/bookings/{booking}/summary', [BookingController::class, 'summary'])->name('bookings.summary');
    Route::get('properties/{property}/bookings/{booking}/edit', [BookingController::class, 'edit'])->name('bookings.edit');
    Route::put('properties/{property}/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
    Route::get('properties/{property}/bookings/{booking}/attachments/{attachment}', [BookingController::class, 'showAttachment'])->name('bookings.attachments.show');
    Route::patch('properties/{property}/bookings/{booking}/status', [BookingController::class, 'updateStatus'])->name('bookings.updateStatus');
    Route::patch('properties/{property}/bookings/{booking}/check-in-status', [BookingController::class, 'updateCheckInStatus'])->name('bookings.updateCheckInStatus');
    Route::patch('properties/{property}/bookings/{booking}/rooms/{subBooking}/stay', [RoomStayController::class, 'update'])->name('bookings.rooms.stay');
    Route::post('properties/{property}/bookings/{booking}/rooms/check-in', [RoomStayController::class, 'checkInRooms'])->name('bookings.rooms.checkIn');
    Route::post('properties/{property}/bookings/{booking}/rooms/check-out', [RoomStayController::class, 'checkOutRooms'])->name('bookings.rooms.checkOut');
    Route::get('properties/{property}/bookings/{booking}/stay-time-charges', [StayTimeChargeController::class, 'preview'])->name('bookings.stayTimeCharges.preview');
    Route::post('properties/{property}/bookings/{booking}/split', [RoomDatesController::class, 'split'])->name('bookings.split');
    Route::post('properties/{property}/bookings/{booking}/rooms/{subBooking}/dates/preview', [RoomDatesController::class, 'preview'])->name('bookings.rooms.datesPreview');
    Route::patch('properties/{property}/bookings/{booking}/rooms/{subBooking}/dates', [RoomDatesController::class, 'update'])->name('bookings.rooms.dates');
    Route::get('properties/{property}/bookings/{booking}/rooms/{subBooking}/upgrade', [RoomUpgradeController::class, 'options'])->name('bookings.rooms.upgradeOptions');
    Route::post('properties/{property}/bookings/{booking}/rooms/{subBooking}/upgrade', [RoomUpgradeController::class, 'store'])->name('bookings.rooms.upgrade');
    Route::post('properties/{property}/bookings/{booking}/folio-transfers', FolioTransferController::class)->name('bookings.folioTransfers.store');
    Route::post('properties/{property}/bookings/{booking}/guest-ledger/deposit', [GuestLedgerController::class, 'deposit'])->name('bookings.guestLedger.deposit');
    Route::post('properties/{property}/bookings/{booking}/guest-ledger/apply', [GuestLedgerController::class, 'apply'])->name('bookings.guestLedger.apply');
    Route::post('properties/{property}/bookings/{booking}/folios', [BookingFolioController::class, 'store'])->name('bookings.folios.store');
    Route::post('properties/{property}/bookings/{booking}/folios/{folio}/windows', [BookingFolioController::class, 'storeWindow'])->name('bookings.folios.windows.store');
    Route::patch('properties/{property}/bookings/{booking}/folio-windows/{subFolio}', [BookingFolioController::class, 'updateWindow'])->name('bookings.folios.windows.update');
    Route::post('properties/{property}/bookings/{booking}/split-payments', SplitPaymentController::class)->name('bookings.splitPayments.store');
    Route::post('properties/{property}/bookings/{booking}/addons', [BookingAddonController::class, 'store'])->name('bookings.addons.store');
    Route::delete('properties/{property}/bookings/{booking}/addons/{bookingAddon}', [BookingAddonController::class, 'destroy'])->name('bookings.addons.destroy');
    Route::get('properties/{property}/bookings/{booking}/web-check-in/link', [BookingWebCheckinController::class, 'link'])->name('bookings.webCheckin.link');
    Route::post('properties/{property}/bookings/{booking}/web-check-in/send', [BookingWebCheckinController::class, 'send'])->name('bookings.webCheckin.send');
    Route::get('properties/{property}/bookings/{booking}/web-check-in/document', [BookingWebCheckinController::class, 'document'])->name('bookings.webCheckin.document');
    Route::get('properties/{property}/bookings/{booking}/web-check-in/signature', [BookingWebCheckinController::class, 'signature'])->name('bookings.webCheckin.signature');
    Route::post('properties/{property}/bookings/{booking}/payment-links', [PaymentLinkController::class, 'store'])->name('bookings.paymentLinks.store');
    Route::post('properties/{property}/bookings/{booking}/payment-links/{paymentLink}/resend', [PaymentLinkController::class, 'resend'])->name('bookings.paymentLinks.resend');
    Route::patch('properties/{property}/bookings/{booking}/payment-links/{paymentLink}/cancel', [PaymentLinkController::class, 'cancel'])->name('bookings.paymentLinks.cancel');

    Route::patch('properties/{property}/rooms/{room}/housekeeping', HousekeepingController::class)->name('rooms.housekeeping');
    Route::post('properties/{property}/room-blocks', [RoomBlockController::class, 'store'])->name('roomBlocks.store');
    Route::post('properties/{property}/room-blocks/unblock', [RoomBlockController::class, 'unblock'])->name('roomBlocks.unblock');
    Route::patch('properties/{property}/room-blocks/{roomBlock}/move', [RoomBlockController::class, 'move'])->name('roomBlocks.move');
    Route::post('properties/{property}/chart-notes', [ChartNoteController::class, 'store'])->name('chartNotes.store');
    Route::put('properties/{property}/chart-notes/{chartNote}', [ChartNoteController::class, 'update'])->name('chartNotes.update');
    Route::patch('properties/{property}/bookings/{booking}/move', MoveBookingController::class)->name('bookings.move');

    Route::get('databases/bookings', [DatabaseController::class, 'bookings'])->name('databases.bookings.index');
    Route::get('databases/bookings/download', [DatabaseController::class, 'bookingsDownload'])->name('databases.bookings.download');
    Route::get('databases/payments', [DatabaseController::class, 'payments'])->name('databases.payments.index');
    Route::get('databases/payments/download', [DatabaseController::class, 'paymentsDownload'])->name('databases.payments.download');
    Route::post('databases/payments', [DatabaseController::class, 'paymentsStore'])->name('databases.payments.store');
    Route::post('databases/payments/{payment}/cancel', [DatabaseController::class, 'paymentsCancel'])->name('databases.payments.cancel');
    Route::get('databases/invoices', [DatabaseController::class, 'invoices'])->name('databases.invoices.index');
    Route::get('databases/folios', [FolioDatabaseController::class, 'index'])->name('databases.folios.index');
    Route::get('databases/folios/download', [FolioDatabaseController::class, 'download'])->name('databases.folios.download');
    Route::get('databases/invoices/download', [DatabaseController::class, 'invoicesDownload'])->name('databases.invoices.download');
    Route::post('databases/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store'])->name('databases.invoices.creditNotes.store');
    Route::get('databases/credit-notes/{creditNote}/print', [CreditNoteController::class, 'print'])->name('databases.invoices.creditNotes.print');
    Route::get('databases/credit-notes/{creditNote}/pdf', [CreditNoteController::class, 'pdf'])->name('databases.invoices.creditNotes.pdf');
    Route::get('general-ledger', [GeneralLedgerController::class, 'index'])->name('generalLedger.index');
    Route::get('general-ledger/{property}', [GeneralLedgerController::class, 'show'])->name('generalLedger.show');
    Route::post('general-ledger/{property}/accounts', [GeneralLedgerController::class, 'storeAccount'])->name('generalLedger.accounts.store');
    Route::put('general-ledger/{property}/accounts/{glAccount}', [GeneralLedgerController::class, 'updateAccount'])->name('generalLedger.accounts.update');
    Route::put('general-ledger/{property}/mappings', [GeneralLedgerController::class, 'updateMappings'])->name('generalLedger.mappings.update');
    Route::get('databases/city-ledger', [CityLedgerController::class, 'index'])->name('databases.cityLedger.index');
    Route::post('databases/city-ledger/{property}/accounts', [CityLedgerController::class, 'store'])->name('databases.cityLedger.store');
    Route::get('databases/city-ledger/accounts/{cityLedgerAccount}', [CityLedgerController::class, 'show'])->name('databases.cityLedger.show');
    Route::put('databases/city-ledger/accounts/{cityLedgerAccount}', [CityLedgerController::class, 'update'])->name('databases.cityLedger.update');
    Route::post('databases/city-ledger/accounts/{cityLedgerAccount}/transfers', [CityLedgerController::class, 'transfer'])->name('databases.cityLedger.transfer');
    Route::post('databases/city-ledger/accounts/{cityLedgerAccount}/payments', [CityLedgerController::class, 'payment'])->name('databases.cityLedger.payment');
    Route::post('databases/city-ledger/accounts/{cityLedgerAccount}/adjustments', [CityLedgerController::class, 'adjust'])->name('databases.cityLedger.adjust');
    Route::get('databases/city-ledger/accounts/{cityLedgerAccount}/statement', [CityLedgerController::class, 'statement'])->name('databases.cityLedger.statement');
    Route::get('databases/enquiries', [DatabaseController::class, 'enquiries'])->name('databases.enquiries.index');
    Route::get('databases/waitlist', [WaitlistController::class, 'index'])->name('databases.waitlist.index');
    Route::post('databases/waitlist/{property}', [WaitlistController::class, 'store'])->name('databases.waitlist.store');
    Route::post('databases/waitlist/{property}/refresh', [WaitlistController::class, 'refresh'])->name('databases.waitlist.refresh');
    Route::patch('databases/waitlist/{property}/{waitlistRequest}/cancel', [WaitlistController::class, 'cancel'])->name('databases.waitlist.cancel');
    Route::post('databases/waitlist/{property}/{waitlistRequest}/convert', [WaitlistController::class, 'convert'])->name('databases.waitlist.convert');
    Route::get('databases/agents', [AgentController::class, 'index'])->name('databases.agents.index');
    Route::get('databases/agents/download', [AgentController::class, 'download'])->name('databases.agents.download');
    Route::get('databases/agents/{property}/search', [AgentController::class, 'search'])->name('databases.agents.search');
    Route::post('databases/agents/{property}', [AgentController::class, 'store'])->name('databases.agents.store');
    Route::put('databases/agents/{property}/{agent}', [AgentController::class, 'update'])->name('databases.agents.update');
    Route::delete('databases/agents/{property}/{agent}', [AgentController::class, 'destroy'])->name('databases.agents.destroy');
    Route::post('databases/agents/{property}/{agent}/restore', [AgentController::class, 'restore'])->name('databases.agents.restore');
    Route::get('databases/guests', [DatabaseController::class, 'guests'])->name('databases.guests.index');
    Route::get('databases/guests/download', [DatabaseController::class, 'guestsDownload'])->name('databases.guests.download');
    Route::get('databases/guests/upload-template', [DatabaseController::class, 'guestUploadTemplate'])->name('databases.guests.uploadTemplate');
    Route::post('databases/guests/upload', [DatabaseController::class, 'guestsUpload'])->name('databases.guests.upload');
    Route::get('databases/guests/{guest}/privacy/export', [GuestPrivacyController::class, 'export'])->name('databases.guests.privacy.export');
    Route::post('databases/guests/{guest}/privacy/erase', [GuestPrivacyController::class, 'erase'])->name('databases.guests.privacy.erase');
    Route::post('databases/guests/{guest}/privacy/withdraw-marketing', [GuestPrivacyController::class, 'withdrawMarketing'])->name('databases.guests.privacy.withdrawMarketing');
    Route::get('databases/guests/{guest}/merge-candidates', [GuestMergeController::class, 'candidates'])->name('databases.guests.merge.candidates');
    Route::post('databases/guests/{guest}/merge', [GuestMergeController::class, 'store'])->name('databases.guests.merge.store');

    Route::get('properties/{property}/guests/companies', [GuestController::class, 'companies'])->name('guests.companies');
    Route::get('properties/{property}/guests/search', [GuestController::class, 'search'])->name('guests.search');
    Route::post('properties/{property}/guests/scan-id', [GuestIdScanController::class, 'store'])->middleware('throttle:30,1')->name('guests.scanId');
    Route::post('properties/{property}/guests', [GuestController::class, 'store'])->name('guests.store');
    Route::put('properties/{property}/guests/{guest}', [GuestController::class, 'update'])->name('guests.update');

    Route::get('booking-engine-settings', [BookingEngineSettingController::class, 'index'])->name('bookingEngineSettings.index');
    Route::get('booking-engine-settings/{property}', [BookingEngineSettingController::class, 'edit'])->name('bookingEngineSettings.edit');
    Route::put('booking-engine-settings/{property}', [BookingEngineSettingController::class, 'update'])->name('bookingEngineSettings.update');

    Route::post('properties/{property}/cancellation-rules', [PropertyCancellationRuleController::class, 'store'])
        ->name('properties.cancellation-rules.store');
    Route::put('properties/{property}/cancellation-rules/{cancellationRule}', [PropertyCancellationRuleController::class, 'update'])
        ->name('properties.cancellation-rules.update');
    Route::delete('properties/{property}/cancellation-rules/{cancellationRule}', [PropertyCancellationRuleController::class, 'destroy'])
        ->name('properties.cancellation-rules.destroy');

    Route::put('properties/{property}/amenities', [PropertyAmenityController::class, 'update'])
        ->name('properties.amenities.update');

    Route::put('properties/{property}/holidays', [PropertyHolidayController::class, 'update'])
        ->name('properties.holidays.update');

    // POST (not PUT): the form carries image uploads.
    Route::post('properties/{property}/branding', [PropertyBrandingController::class, 'update'])
        ->name('properties.branding.update');

    Route::post('properties/{property}/addons', [PropertyAddonController::class, 'store'])->name('properties.addons.store');
    Route::put('properties/{property}/addons/{addon}', [PropertyAddonController::class, 'update'])->scopeBindings()->name('properties.addons.update');
    Route::delete('properties/{property}/addons/{addon}', [PropertyAddonController::class, 'destroy'])->scopeBindings()->name('properties.addons.destroy');
    Route::put('properties/{property}/web-check-in', [PropertyWebCheckinController::class, 'update'])->name('properties.webCheckin.update');
    Route::put('properties/{property}/stay-time-charges', [StayTimeChargeController::class, 'update'])->name('properties.stayTimeCharges.update');

    Route::scopeBindings()->group(function (): void {
        Route::post('properties/{property}/room-categories', [PropertyRoomCategoryController::class, 'store'])
            ->name('properties.room-categories.store');
        Route::put('properties/{property}/room-categories/reorder', [PropertyRoomCategoryController::class, 'reorder'])
            ->name('properties.room-categories.reorder');
        Route::put('properties/{property}/room-categories/upsell-order', [PropertyRoomCategoryController::class, 'upsellOrder'])
            ->name('properties.room-categories.upsell-order');
        Route::put('properties/{property}/room-categories/{roomCategory}', [PropertyRoomCategoryController::class, 'update'])
            ->name('properties.room-categories.update');
        Route::delete('properties/{property}/room-categories/{roomCategory}', [PropertyRoomCategoryController::class, 'destroy'])
            ->name('properties.room-categories.destroy');
        Route::put('properties/{property}/room-categories/{roomCategory}/amenities', [RoomCategoryAmenityController::class, 'update'])
            ->name('properties.room-categories.amenities.update');

        Route::post('properties/{property}/room-categories/{roomCategory}/rooms', [RoomController::class, 'store'])
            ->name('properties.room-categories.rooms.store');
        Route::put('properties/{property}/room-categories/{roomCategory}/rooms/reorder', [RoomController::class, 'reorder'])
            ->name('properties.room-categories.rooms.reorder');
        Route::put('properties/{property}/room-categories/{roomCategory}/rooms/{room}', [RoomController::class, 'update'])
            ->name('properties.room-categories.rooms.update');
        Route::delete('properties/{property}/room-categories/{roomCategory}/rooms/{room}', [RoomController::class, 'destroy'])
            ->name('properties.room-categories.rooms.destroy');

        Route::post('properties/{property}/room-categories/{roomCategory}/images', [RoomCategoryImageController::class, 'store'])
            ->name('properties.room-categories.images.store');
        Route::put('properties/{property}/room-categories/{roomCategory}/images/{image}', [RoomCategoryImageController::class, 'update'])
            ->name('properties.room-categories.images.update');
        Route::delete('properties/{property}/room-categories/{roomCategory}/images/{image}', [RoomCategoryImageController::class, 'destroy'])
            ->name('properties.room-categories.images.destroy');

        Route::put('properties/{property}/room-categories/{roomCategory}/bed-types', [RoomCategoryBedTypeController::class, 'updateBeds'])
            ->name('properties.room-categories.bed-types.update');
        Route::put('properties/{property}/room-categories/{roomCategory}/extra-bed-types', [RoomCategoryBedTypeController::class, 'updateExtraBeds'])
            ->name('properties.room-categories.extra-bed-types.update');
    });
};
