<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\User;
use App\Models\WebCheckin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Guests' personal data under GDPR and India's DPDP Act: the consents they give (terms,
 * privacy policy, marketing) kept as evidence, a copy of everything held about a guest, and
 * erasure. Erasing removes the guest's personal details, ID documents and signatures but
 * keeps bookings, invoices and payments (tax law requires them) under an anonymous name.
 */
class GuestPrivacy
{
    /**
     * Guest columns that are internal bookkeeping, left out of a guest's data export (installed
     * packages add their own).
     *
     * @var list<string>
     */
    public static array $internalGuestColumns = [];

    public function __construct(private LegalSettings $legal) {}

    /**
     * Records what the guest agreed to (and a marketing refusal, so the choice is on record);
     * marketing also sets the guest's newsletter flag.
     *
     * @param  array<string, bool>  $choices  purpose => granted
     */
    public function recordConsents(Property $property, ?Guest $guest, ?Booking $booking, array $choices, string $source, ?string $ip, ?string $userAgent): void
    {
        $version = $this->legal->version($property);

        foreach ($choices as $purpose => $granted) {
            GuestConsent::query()->create([
                'property_id' => $property->id,
                'guest_id' => $guest?->id,
                'booking_id' => $booking?->id,
                'purpose' => $purpose,
                'granted' => $granted,
                'policy_version' => $version,
                'source' => $source,
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ]);
        }

        if ($guest !== null && array_key_exists(GuestConsent::MARKETING, $choices)) {
            $guest->forceFill(['subscribe_newsletter' => $choices[GuestConsent::MARKETING]])->saveQuietly();
        }
    }

    /**
     * Withdraws the guest's marketing consent: no more offers.
     */
    public function withdrawMarketing(Guest $guest, User $user): void
    {
        DB::transaction(function () use ($guest, $user): void {
            GuestConsent::query()->where('guest_id', $guest->id)->where('purpose', GuestConsent::MARKETING)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
            $guest->forceFill(['subscribe_newsletter' => false])->saveQuietly();

            $this->log($guest, $user, "Marketing consent of guest #{$guest->id} withdrawn.");
        });
    }

    /**
     * Everything held about the guest, for a data access request.
     *
     * @return array<string, mixed>
     */
    public function export(Guest $guest): array
    {
        $guest->loadMissing('property');
        $bookings = Booking::query()->whereHas('guests', fn ($query) => $query->whereKey($guest->id))
            ->with(['rooms.roomCategory:id,name', 'payments'])
            ->orderBy('check_in')
            ->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'controller' => $guest->property?->name,
            'guest' => collect($guest->attributesToArray())->except(['property_id', ...self::$internalGuestColumns])->all(),
            'consents' => GuestConsent::query()->where('guest_id', $guest->id)->orderBy('id')->get()
                ->map(fn (GuestConsent $consent): array => $consent->only(['purpose', 'granted', 'policy_version', 'source', 'ip_address', 'created_at', 'withdrawn_at']))->all(),
            'bookings' => $bookings->map(fn (Booking $booking): array => [
                'booking_ref_id' => $booking->booking_ref_id,
                'status' => $booking->status->label(),
                'check_in' => $booking->check_in?->toDateString(),
                'check_out' => $booking->check_out?->toDateString(),
                'rooms' => $booking->rooms->map(fn ($room): array => ['category' => $room->roomCategory?->name, 'meal_plan' => $room->meal_plan, 'adults' => $room->adults, 'children' => $room->children])->all(),
                'total' => (float) $booking->total,
                'special_instructions' => $booking->special_instructions,
                'payments' => $booking->payments->map(fn ($payment): array => ['receipt_number' => $payment->receipt_number, 'mode' => $payment->payment_mode?->value, 'amount' => (float) $payment->total_txn_amount, 'date' => $payment->payment_transaction_date?->toIso8601String()])->all(),
                'invoices' => Invoice::query()->where('booking_id', $booking->id)->get()->map(fn ($invoice): array => ['invoice_number' => $invoice->invoice_number, 'amount' => (float) $invoice->invoice_amount, 'date' => $invoice->invoice_generation_date?->toIso8601String()])->all(),
                'web_checkin' => WebCheckin::query()->where('booking_id', $booking->id)->value('details'),
            ])->all(),
        ];
    }

    /**
     * Erases the guest's personal data. Refused while one of their bookings is still to come
     * or in house (the stay needs it).
     *
     * @throws ValidationException
     */
    public function erase(Guest $guest, ?User $user, string $reason = 'on request'): void
    {
        $active = Booking::query()->whereHas('guests', fn ($query) => $query->whereKey($guest->id))
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::Pending, BookingStatus::CheckedIn])
            ->pluck('booking_ref_id');

        if ($active->isNotEmpty()) {
            throw ValidationException::withMessages(['guest' => 'This guest has a booking still to come or in house ('.$active->implode(', ').'); erase their data after the stay.']);
        }

        DB::transaction(function () use ($guest, $user, $reason): void {
            $bookingIds = Booking::query()->whereHas('guests', fn ($query) => $query->whereKey($guest->id))->pluck('id');

            // ID documents and signatures from web check-in.
            WebCheckin::query()->whereIn('booking_id', $bookingIds)->get()->each(function (WebCheckin $checkin): void {
                foreach ([$checkin->id_document_path, $checkin->signature_path] as $path) {
                    if ($path !== null) {
                        Storage::disk('local')->delete($path);
                    }
                }

                $checkin->forceFill(['details' => null, 'id_document_path' => null, 'signature_path' => null, 'submitted_ip' => null])->saveQuietly();
            });

            $guest->forceFill([
                'title' => null,
                'first_name' => 'Erased',
                'last_name' => "Guest #{$guest->id}",
                'phone_country_code' => null,
                'phone' => null,
                'emails' => [],
                'gender' => null,
                'dob' => null,
                'nationality' => null,
                'purpose_of_visit' => null,
                'guest_preferences' => null,
                'subscribe_newsletter' => false,
                'anniversary' => null,
                'frequent_flyer' => null,
                'vehicle_number' => null,
                'place_of_birth' => null,
                'present_business_address' => null,
                'address' => null,
                'company' => null,
                'international_details' => null,
            ])->saveQuietly();

            GuestConsent::query()->where('guest_id', $guest->id)->update(['ip_address' => null, 'user_agent' => null]);

            // The retention policy erases with no one signed in; its run is logged as a whole.
            if ($user !== null) {
                $this->log($guest, $user, "Personal data of guest #{$guest->id} erased {$reason}.");
            }
        });
    }

    private function log(Guest $guest, User $user, string $action): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'property_id' => $guest->property_id,
            'guest_id' => $guest->id,
            'action' => $action,
            'date' => now(),
        ]);
    }
}
