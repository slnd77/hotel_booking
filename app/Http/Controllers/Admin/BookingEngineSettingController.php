<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaField;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingEngineSettingController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SETTING_KEYS = [
        'booking_engine_settings',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/booking-engine-settings/Index', [
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
        ]);
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('admin/booking-engine-settings/Edit', [
            'property' => $property->only(['id', 'name', 'ulid']),
            'metaFields' => MetaField::query()->whereIn('key', self::SETTING_KEYS)->orderBy('id')->get(['id', 'key', 'key_label']),
            'metaFieldValues' => $property->metaFieldValues()
                ->whereIn('meta_fields_key_id', MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id'))
                ->get(['id', 'meta_fields_key_id', 'enabled', 'data']),
            'roomCategories' => $property->roomCategories()
                ->orderBy('display_order')
                ->get(['id', 'name', 'display_order']),
            'configuredPaymentGateways' => [
                ['id' => 'razorpay', 'name' => 'Razorpay', 'description' => 'Credit/Debit Cards, UPI, Netbanking, Wallets', 'is_active' => true],
                ['id' => 'stripe', 'name' => 'Stripe', 'description' => 'International & Domestic Cards, Apple Pay, Google Pay', 'is_active' => true],
                ['id' => 'paypal', 'name' => 'PayPal', 'description' => 'PayPal Express Checkout & Credit Cards', 'is_active' => true],
                ['id' => 'ccavenue', 'name' => 'CCAvenue', 'description' => 'Multi-currency payment gateway for Indian & International guests', 'is_active' => true],
                ['id' => 'cashfree', 'name' => 'Cashfree Payments', 'description' => 'Instant UPI, Cards & Netbanking checkout', 'is_active' => true],
            ],
        ]);
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $settingFieldIds = MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id')->all();

        $data = $request->validate([
            'meta_fields' => ['array'],
            'meta_fields.*.enabled' => ['boolean'],
            'meta_fields.*.data' => ['nullable', 'string'],
        ]);

        foreach ($data['meta_fields'] ?? [] as $metaFieldKeyId => $value) {
            if (! in_array((int) $metaFieldKeyId, $settingFieldIds, true)) {
                continue;
            }

            $property->metaFieldValues()->updateOrCreate(
                ['meta_fields_key_id' => $metaFieldKeyId],
                ['enabled' => $value['enabled'] ?? false, 'data' => $value['data'] ?? null],
            );
        }

        return redirect()->route('admin.bookingEngineSettings.edit', $property)->with('success', 'Booking engine settings updated.');
    }
}
