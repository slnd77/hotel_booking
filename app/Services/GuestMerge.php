<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Databases → Guests → Merge: one person entered twice (a walk-in and an OTA booking, a
 * typo in the phone…) becomes one guest. The kept guest takes over the duplicate's
 * bookings, consents and activity, fills its blank details from the duplicate, and the
 * duplicate is deleted.
 */
class GuestMerge
{
    /** Details copied from the duplicate when the kept guest has none. @var list<string> */
    private const SCALAR_FIELDS = [
        'title', 'last_name', 'phone_country_code', 'phone', 'gender', 'dob', 'nationality', 'purpose_of_visit',
        'guest_preferences', 'anniversary', 'frequent_flyer', 'vehicle_number', 'place_of_birth', 'present_business_address',
    ];

    /** Groups of details merged key by key, the kept guest's values winning. @var list<string> */
    private const ARRAY_FIELDS = ['address', 'company', 'international_details'];

    /**
     * Guests of the same property who look like the same person: same phone, a shared e-mail
     * or the same full name. With a search term, any guest matching it instead.
     *
     * @return Collection<int, Guest>
     */
    public function candidates(Guest $guest, ?string $term = null, int $limit = 10): Collection
    {
        $query = Guest::query()->where('property_id', $guest->property_id)->whereKeyNot($guest->id);

        if (filled($term)) {
            $query->where(function ($query) use ($term): void {
                $query->whereLike('first_name', "%{$term}%")
                    ->orWhereLike('last_name', "%{$term}%")
                    ->orWhereLike('phone', "%{$term}%")
                    ->orWhereLikeText('emails', "%{$term}%");

                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }
            });
        } else {
            $emails = $this->emails($guest);

            if (blank($guest->phone) && $emails === [] && blank($guest->last_name)) {
                return new Collection;
            }

            $query->where(function ($query) use ($guest, $emails): void {
                if (filled($guest->phone)) {
                    $query->orWhere('phone', $guest->phone);
                }

                foreach ($emails as $email) {
                    $query->orWhereLikeText('emails', '%"'.$email.'"%');
                }

                if (filled($guest->last_name)) {
                    $query->orWhere(fn ($query) => $query->where('first_name', $guest->first_name)->where('last_name', $guest->last_name));
                }
            });
        }

        return $query->orderBy('first_name')->limit($limit)->get();
    }

    /**
     * Merges the duplicate into the kept guest and deletes the duplicate.
     *
     * @throws ValidationException when the two can't be merged
     */
    public function merge(Guest $keep, Guest $duplicate, User $user): Guest
    {
        if ($keep->is($duplicate)) {
            throw ValidationException::withMessages(['duplicate_id' => 'Pick another guest to merge.']);
        }

        if ($keep->property_id !== $duplicate->property_id) {
            throw ValidationException::withMessages(['duplicate_id' => 'Only guests of the same property can be merged.']);
        }

        return DB::transaction(function () use ($keep, $duplicate, $user): Guest {
            // Bookings both guests are on keep one row; the rest move over.
            $sharedBookingIds = DB::table('booking_guests')->where('guest_id', $keep->id)->pluck('booking_id');
            DB::table('booking_guests')->where('guest_id', $duplicate->id)->whereIn('booking_id', $sharedBookingIds)->delete();
            DB::table('booking_guests')->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]);

            Booking::query()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]);
            GuestConsent::query()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]);
            ActivityLog::query()->where('guest_id', $duplicate->id)->update(['guest_id' => $keep->id]);

            $keep->forceFill($this->mergedDetails($keep, $duplicate))->saveQuietly();

            $duplicateName = trim("{$duplicate->first_name} {$duplicate->last_name}");
            $duplicateId = $duplicate->id;
            $duplicate->delete();

            ActivityLog::create([
                'user_id' => $user->id,
                'property_id' => $keep->property_id,
                'guest_id' => $keep->id,
                'action' => "Guest #{$duplicateId} ({$duplicateName}) merged into guest #{$keep->id}.",
                'date' => now(),
            ]);

            return $keep->refresh();
        });
    }

    /**
     * The kept guest's details with its blanks filled from the duplicate. Marketing follows
     * the latest marketing consent on record (both guests' consents are the kept guest's now);
     * with none, the kept guest's own choice stands.
     *
     * @return array<string, mixed>
     */
    private function mergedDetails(Guest $keep, Guest $duplicate): array
    {
        $details = [];

        foreach (self::SCALAR_FIELDS as $field) {
            if (blank($keep->getAttribute($field)) && filled($duplicate->getAttribute($field))) {
                $details[$field] = $duplicate->getAttribute($field);
            }
        }

        foreach (self::ARRAY_FIELDS as $field) {
            $merged = [...array_filter((array) $duplicate->getAttribute($field), 'filled'), ...array_filter((array) $keep->getAttribute($field), 'filled')];
            $details[$field] = $merged === [] ? $keep->getAttribute($field) : $merged;
        }

        $details['emails'] = array_values(array_unique([...$this->emails($keep), ...$this->emails($duplicate)]));

        $marketing = GuestConsent::query()->where('guest_id', $keep->id)->where('purpose', GuestConsent::MARKETING)->latest('id')->first();
        $details['subscribe_newsletter'] = $marketing !== null ? $marketing->granted && $marketing->withdrawn_at === null : (bool) $keep->subscribe_newsletter;

        return $details;
    }

    /**
     * @return list<string>
     */
    private function emails(Guest $guest): array
    {
        return array_values(array_filter(array_map(fn (mixed $email): string => mb_strtolower(trim((string) $email)), (array) $guest->emails), 'filled'));
    }
}
