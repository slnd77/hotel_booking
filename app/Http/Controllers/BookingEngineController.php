<?php

namespace App\Http\Controllers;

use App\Classes\PaymentGateway\PaymentGatewayManager;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\WaitlistRequest;
use App\Services\BookingEnginePromos;
use App\Services\BookingEngineService;
use App\Services\Waitlists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookingEngineController extends Controller
{
    public function __construct(
        protected BookingEngineService $bookingEngineService,
    ) {}

    public function web(Request $request, Property $property): Response
    {
        // The search widget sends `rooms` as a JSON string (Inertia's query serializer
        // doesn't nest an array of objects correctly), so decode it before validating.
        if (is_string($request->query('rooms'))) {
            $decoded = json_decode((string) $request->query('rooms'), true);
            $request->query->set('rooms', is_array($decoded) ? $decoded : null);
        }

        // A shared or bookmarked link may carry dates that have passed (in the property's own
        // calendar): fall back to the default search.
        $today = $property->today();

        if ($request->filled('check_in') && strtotime((string) $request->query('check_in')) !== false
            && date('Y-m-d', strtotime((string) $request->query('check_in'))) < $today) {
            $request->query->remove('check_in');
            $request->query->remove('check_out');
        }

        $validated = $request->validate([
            'check_in' => ['nullable', 'date', 'after_or_equal:'.$today],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'rooms' => ['nullable', 'array', 'min:1', 'max:10'],
            'rooms.*.room_category_id' => ['nullable', 'integer', 'exists:room_categories,id'],
            'rooms.*.adults' => ['nullable', 'integer', 'min:1', 'max:10'],
            'rooms.*.children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'rooms.*.infants' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $pageData = $this->bookingEngineService->getWebPageData($property, $validated);

        if ($pageData === null) {
            return Inertia::render('BookingEngine/Disabled', [
                'property' => ['name' => $property->name],
            ]);
        }

        return Inertia::render('BookingEngine/Web', $pageData);
    }

    public function calendar(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        $calendarData = $this->bookingEngineService->getCalendarData($property, $data['from'], $data['to']);

        return response()->json($calendarData);
    }

    /**
     * The server's price for what the guest has picked: rooms, promo code, add-ons and full
     * or partial payment. The page shows these figures and never prices the stay itself.
     */
    /**
     * Sold out for the dates: the guest joins the waitlist, for staff to convert once rooms free up.
     */
    public function joinWaitlist(Request $request, Property $property, Waitlists $waitlists): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date', 'after_or_equal:'.$property->today()],
            'check_out' => ['required', 'date', 'after:check_in'],
            'room_category_id' => ['nullable', 'integer', Rule::exists(RoomCategory::class, 'id')->where('property_id', $property->id)],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:10'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'guest' => ['required', 'array'],
            'guest.name' => ['required', 'string', 'max:255'],
            'guest.phone' => ['required', 'string', 'max:30'],
            'guest.email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['check_in.after_or_equal' => 'The check-in date cannot be in the past.']);

        $item = $waitlists->create($property, [
            'guest_name' => $data['guest']['name'],
            'phone' => $data['guest']['phone'],
            'email' => $data['guest']['email'] ?? null,
            'room_category_id' => $data['room_category_id'] ?? null,
            'check_in' => date('Y-m-d', strtotime($data['check_in'])),
            'check_out' => date('Y-m-d', strtotime($data['check_out'])),
            'rooms' => $data['rooms'] ?? 1,
            'adults' => $data['adults'] ?? 2,
            'children' => $data['children'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ], WaitlistRequest::SOURCE_BOOKING_ENGINE);

        return response()->json([
            'success' => true,
            'status' => $item->status->value,
            'message' => "You're on the waitlist for {$item->check_in->format('d M')} – {$item->check_out->format('d M Y')}. We'll contact you at {$item->phone} if rooms free up.",
        ]);
    }

    public function quote(Request $request, Property $property): JsonResponse
    {
        $quote = $this->bookingEngineService->quote($property, $this->validatedSelection($request, $property));

        return response()->json(['success' => true, 'quote' => $quote, 'promo' => $this->appliedPromo($property, $quote)]);
    }

    public function initiateCheckout(Request $request, Property $property): JsonResponse
    {
        $validated = [
            ...$this->validatedSelection($request, $property),
            ...$request->validate([
                'payment_gateway' => ['required', 'string', Rule::in(PaymentGatewayManager::allowedGatewayNames())],
                'guest' => ['required', 'array'],
                'guest.name' => ['required', 'string', 'max:255'],
                'guest.phone' => ['required', 'string', 'max:30'],
                'guest.email' => ['required', 'email', 'max:255'],
                'guest.special_requests' => ['nullable', 'string', 'max:2000'],
                // The guest accepts the property's terms and privacy policy; marketing is their choice.
                'consent' => ['required', 'array'],
                'consent.terms' => ['accepted'],
                'consent.marketing' => ['nullable', 'boolean'],
            ], ['consent.terms.accepted' => 'Please accept the terms of booking and the privacy policy.', 'consent.required' => 'Please accept the terms of booking and the privacy policy.']),
        ];
        $validated['consent'] = [
            'marketing' => (bool) ($validated['consent']['marketing'] ?? false),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        $result = $this->bookingEngineService->initiateCheckout($property, $validated);
        $status = ($result['success'] ?? true) ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * Books a checkout this server priced, once its payment is verified. Only the payment's
     * references are taken from the page; the stay and its price come from the checkout.
     */
    public function confirmCheckout(Request $request, Property $property): JsonResponse
    {
        $validated = $request->validate([
            'booking_ref_id' => ['required', 'string', 'max:40'],
            'payment_gateway' => ['required', 'string', Rule::in(PaymentGatewayManager::allowedGatewayNames())],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'max:512'],
        ]);

        $bookingData = $this->bookingEngineService->confirmCheckout($property, $validated);

        return response()->json([
            'success' => true,
            'booking' => $bookingData,
        ]);
    }

    /**
     * What the guest picked: dates, rooms (category, meal plan, guests), add-ons, promo code
     * and full or partial payment. No prices.
     *
     * @return array<string, mixed>
     */
    private function validatedSelection(Request $request, Property $property): array
    {
        return $request->validate([
            'check_in' => ['required', 'date', 'after_or_equal:'.$property->today()],
            'check_out' => ['required', 'date', 'after:check_in'],
            'payment_type' => ['nullable', 'string', 'in:partial,full'],
            'promo_code' => ['nullable', 'string', 'max:50'],
            'rooms' => ['required', 'array', 'min:1', 'max:10'],
            'rooms.*.category_id' => ['required', 'integer'],
            'rooms.*.meal_plan' => ['nullable', 'string', 'max:20'],
            'rooms.*.package_id' => ['nullable', 'integer', 'min:1'],
            'rooms.*.adults' => ['nullable', 'integer', 'min:1', 'max:10'],
            'rooms.*.children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'rooms.*.infants' => ['nullable', 'integer', 'min:0', 'max:10'],
            'addons' => ['nullable', 'array', 'max:20'],
            'addons.*.addon_id' => ['required', 'integer'],
            'addons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ], ['check_in.after_or_equal' => 'The check-in date cannot be in the past.']);
    }

    /**
     * The applied code's rules for the page (a secret code's reach it only once it applies).
     *
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>|null
     */
    private function appliedPromo(Property $property, array $quote): ?array
    {
        $promos = app(BookingEnginePromos::class);
        $promo = $quote['promo_code'] !== null ? $promos->find($property, $quote['promo_code']) : null;

        return $promo !== null ? $promos->forDisplay($promo) : null;
    }
}
