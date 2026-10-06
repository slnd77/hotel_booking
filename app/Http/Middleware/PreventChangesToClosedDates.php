<?php

namespace App\Http\Middleware;

use App\Models\Booking;
use App\Models\OutletBill;
use App\Models\Payment;
use App\Models\Property;
use App\Services\ClosedDates;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Night audit → "Freeze all past dated data": refuses changes to bookings whose stay
 * ended on a closed business date, to payments dated on one, and new or moved stays
 * and payments dated into one, and voiding a POS bill of a closed day. Collecting a
 * payment today for an old booking is fine.
 */
class PreventChangesToClosedDates
{
    /** Routes that change a booking's stay. */
    private const BOOKING_ROUTES = [
        'admin.bookings.update',
        'admin.bookings.updateStatus',
        'admin.bookings.updateCheckInStatus',
        'admin.bookings.move',
        'admin.bookings.split',
        'admin.bookings.rooms.checkIn',
        'admin.bookings.rooms.checkOut',
        'admin.bookings.rooms.dates',
        'admin.bookings.rooms.stay',
        // The same actions through the API.
        'api.v1.bookings.updateStatus',
        'api.v1.bookings.move',
    ];

    public function __construct(private ClosedDates $closedDates) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        // The mobile app's copy of the panel (api.v1.panel.*) is checked as the admin route it mirrors.
        $routeIs = fn (string ...$patterns): bool => $request->routeIs(...$patterns)
            || Str::is($patterns, (string) ServePanelOverApi::adminRouteName($request->route()?->getName()));

        $property = $request->route('property');
        $booking = $request->route('booking');
        $payment = $request->route('payment');
        $outletBill = $request->route('outletBill');

        $refused = match (true) {
            $booking instanceof Booking && $routeIs(...self::BOOKING_ROUTES) => $this->closedDates->isBookingFrozen($booking) || $this->movesIntoClosedDate($request, $booking),
            $property instanceof Property && $routeIs('admin.bookings.store', 'api.v1.bookings.store') => $this->closedDates->isClosed($property, (string) $request->input('check_in')),
            $payment instanceof Payment && $routeIs('admin.databases.payments.cancel') => $this->closedDates->isPaymentFrozen($payment),
            $routeIs('admin.databases.payments.store') => $this->datesPaymentIntoClosedDate($request),
            $outletBill instanceof OutletBill && $routeIs('admin.pos.bills.void', 'api.v1.outletBills.void') => $this->closedDates->isClosed($outletBill->property, $outletBill->business_date->toDateString()),
            default => false,
        };

        if (! $refused) {
            return $next($request);
        }

        $message = $this->closedDates->message($this->propertyOf($request) ?? new Property);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->with('error', $message);
    }

    /**
     * A new arrival date (the booking's or one room's) that falls on a closed day.
     */
    private function movesIntoClosedDate(Request $request, Booking $booking): bool
    {
        $current = $booking->rooms()->get()->map(fn ($room): string => $room->check_in_date->toDateString())->push($booking->check_in->toDateString());

        return collect([$request->input('check_in'), $request->input('check_in_date'), ...collect($request->array('rooms'))->pluck('check_in_date')])
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->contains(fn (string $date): bool => ! $current->contains(substr($date, 0, 10)) && $this->closedDates->isClosed($booking->property, $date));
    }

    private function datesPaymentIntoClosedDate(Request $request): bool
    {
        $property = $this->propertyOf($request);
        $date = $request->input('payment_transaction_date');

        if ($property === null || ! is_string($date) || $date === '') {
            return false;
        }

        try {
            return $this->closedDates->isClosed($property, $this->closedDates->localDate($property, CarbonImmutable::parse($date)));
        } catch (Throwable) {
            return false;
        }
    }

    private function propertyOf(Request $request): ?Property
    {
        foreach (['property', 'booking', 'payment'] as $parameter) {
            $bound = $request->route($parameter);

            if ($bound instanceof Property) {
                return $bound;
            }

            if ($bound instanceof Booking || $bound instanceof Payment) {
                return $bound->property;
            }
        }

        $id = $request->input('payment_received_at') ?? $request->input('property_id') ?? $request->query('property');

        return is_numeric($id) ? Property::query()->find($id) : null;
    }
}
