<?php

namespace App\Http\Controllers;

use App\Models\GuestConsent;
use App\Models\WebCheckin;
use App\Services\BookingPrints;
use App\Services\GuestPrivacy;
use App\Services\LegalSettings;
use App\Services\WebCheckins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guest's web check-in page, opened from the link in their confirmation e-mail (or one
 * the front desk sent): their details, ID document and signature before arrival.
 */
class WebCheckinPageController extends Controller
{
    /** @var list<string> */
    public const ID_TYPES = ['Passport', 'Aadhaar', 'Driving Licence', 'Voter ID', 'National ID', 'Other'];

    public function __construct(private WebCheckins $checkins, private BookingPrints $prints) {}

    public function show(WebCheckin $webCheckin): Response
    {
        $webCheckin->load(['booking.property', 'booking.rooms.roomCategory:id,name', 'booking.guests']);
        $booking = $webCheckin->booking;
        $settings = $this->checkins->settings($booking->property);
        $guest = $booking->guests->first();
        $details = $webCheckin->details ?? [];

        return Inertia::render('WebCheckin/Show', [
            'token' => $webCheckin->token,
            'property' => ['name' => $booking->property->name],
            'booking' => [
                'booking_ref_id' => $booking->booking_ref_id,
                'check_in' => $booking->check_in->format('d M Y'),
                'check_out' => $booking->check_out->format('d M Y'),
                'nights' => max(1, (int) $booking->check_in->diffInDays($booking->check_out)),
                'rooms' => $booking->rooms->groupBy(fn ($room): string => $room->roomCategory->name ?? 'Room')->map(fn ($rooms, string $name): string => "{$rooms->count()} × {$name}")->values()->all(),
                'guests' => (int) $booking->rooms->sum(fn ($room): int => (int) $room->adults + (int) $room->children),
            ],
            'settings' => array_intersect_key($settings, array_flip(['require_id_document', 'require_signature', 'require_address', 'ask_arrival_time', 'instructions', 'terms'])),
            'idTypes' => self::ID_TYPES,
            'status' => $webCheckin->status,
            'submittedAt' => $webCheckin->submitted_at?->toIso8601ZuluString(),
            'closedReason' => $this->checkins->closedReason($webCheckin),
            'hasIdDocument' => filled($webCheckin->id_document_path),
            'legal' => [
                'privacy_url' => route('legal.privacy', $booking->property),
                'marketing_opt_in' => app(LegalSettings::class)->get($booking->property)['marketing_opt_in'],
            ],
            'prefill' => [
                'first_name' => $details['first_name'] ?? $guest->first_name ?? '',
                'last_name' => $details['last_name'] ?? $guest->last_name ?? '',
                'email' => $details['email'] ?? ($this->prints->guest($guest)['email'] ?? ''),
                'phone' => $details['phone'] ?? $guest->phone ?? '',
                'nationality' => $details['nationality'] ?? $guest->nationality ?? '',
                'address' => $details['address'] ?? ($guest?->address['address'] ?? ''),
                'postal_code' => $details['postal_code'] ?? ($guest?->address['postal_code'] ?? ''),
                'id_type' => $details['id_type'] ?? '',
                'id_number' => $details['id_number'] ?? '',
                'arrival_time' => $details['arrival_time'] ?? '',
                'special_requests' => $details['special_requests'] ?? '',
            ],
        ]);
    }

    public function submit(Request $request, WebCheckin $webCheckin): RedirectResponse
    {
        $webCheckin->load('booking.property');

        if (($reason = $this->checkins->closedReason($webCheckin)) !== null) {
            throw ValidationException::withMessages(['form' => $reason]);
        }

        $settings = $this->checkins->settings($webCheckin->booking->property);
        $needsDocument = $settings['require_id_document'] && blank($webCheckin->id_document_path);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'address' => [$settings['require_address'] ? 'required' : 'nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'id_type' => [$settings['require_id_document'] ? 'required' : 'nullable', 'string', Rule::in(self::ID_TYPES)],
            'id_number' => [$settings['require_id_document'] ? 'required' : 'nullable', 'string', 'max:50'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'id_document' => [$needsDocument ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'signature' => [$settings['require_signature'] ? 'required' : 'nullable', 'string', 'max:700000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
            'accept_terms' => [filled($settings['terms']) ? 'accepted' : 'nullable'],
            'accept_privacy' => ['accepted'],
            'marketing' => ['nullable', 'boolean'],
        ], [
            'accept_privacy.accepted' => 'Please accept the privacy policy to check in online.',
            'signature.required' => 'Please sign in the box.',
            'signature.regex' => 'Please sign in the box again.',
            'id_document.required' => 'Please upload a photo or scan of your ID.',
        ]);

        $details = Arr::except($data, ['id_document', 'signature', 'accept_terms', 'accept_privacy', 'marketing']);
        $this->checkins->submit($webCheckin, $details, $request->file('id_document'), $data['signature'] ?? null, $request->ip());

        $booking = $webCheckin->booking;
        $property = $booking->property;
        app(GuestPrivacy::class)->recordConsents($property, $booking->guests()->first(), $booking, [
            ...(filled($settings['terms']) ? [GuestConsent::TERMS => true] : []),
            GuestConsent::PRIVACY => true,
            ...(app(LegalSettings::class)->get($property)['marketing_opt_in'] ? [GuestConsent::MARKETING => (bool) ($data['marketing'] ?? false)] : []),
        ], 'web_checkin', $request->ip(), $request->userAgent());

        return back()->with('success', 'Thank you! Your online check-in is complete.');
    }
}
