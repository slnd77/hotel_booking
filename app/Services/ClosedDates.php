<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\NightAudit;
use App\Models\Payment;
use App\Models\Property;
use Carbon\CarbonInterface;

/**
 * Night audit → "Freeze all past dated data": once the night audit has closed a
 * business date, what belongs to it (stays that ended by then, payments dated then)
 * can no longer be changed, and nothing new can be dated into it.
 */
class ClosedDates
{
    public function __construct(private NightAuditSettings $settings) {}

    /**
     * The last business date the night audit closed (Y-m-d), while the freeze is on.
     */
    public function frozenThrough(Property $property): ?string
    {
        if (! $this->settings->get($property)['freeze_past_dated_data']) {
            return null;
        }

        $lastClosed = NightAudit::query()->where('property_id', $property->id)->where('status', NightAudit::COMPLETED)->max('business_date');

        return $lastClosed ? substr((string) $lastClosed, 0, 10) : null;
    }

    public function isClosed(Property $property, string $businessDate): bool
    {
        $frozenThrough = $this->frozenThrough($property);

        return $frozenThrough !== null && substr($businessDate, 0, 10) <= $frozenThrough;
    }

    /**
     * A stay that was over by the last closed day. A guest still in house (an overstay)
     * can always be checked out.
     */
    public function isBookingFrozen(Booking $booking): bool
    {
        return $booking->status !== BookingStatus::CheckedIn
            && $booking->property !== null
            && $this->isClosed($booking->property, $booking->check_out->toDateString());
    }

    public function isPaymentFrozen(Payment $payment): bool
    {
        return $payment->property !== null
            && $payment->payment_transaction_date !== null
            && $this->isClosed($payment->property, $this->localDate($payment->property, $payment->payment_transaction_date));
    }

    /**
     * An instant's business date at the property.
     */
    public function localDate(Property $property, CarbonInterface $at): string
    {
        return $property->localTime($at)->toDateString();
    }

    public function message(Property $property): string
    {
        $frozenThrough = $this->frozenThrough($property);

        return 'Business dates up to '.date('d/m/Y', strtotime((string) $frozenThrough)).' are closed by the night audit and frozen.';
    }
}
