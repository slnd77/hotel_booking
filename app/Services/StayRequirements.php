<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Guest;

/**
 * Guest details Application Settings → Reservation requires before a stay (or one
 * room of it) can be checked in or out.
 */
class StayRequirements
{
    /**
     * Guest details required by Application Settings → Reservation before a
     * booking can be checked in or out, as human-readable problems.
     *
     * @return list<string>
     */
    public function missing(Booking $booking, bool $isCheckIn): array
    {
        $reservation = app(ApplicationSettings::class)->get(ApplicationSettings::RESERVATION);
        $required = array_keys(array_filter($isCheckIn ? $reservation['check_in'] : $reservation['check_out']));

        // Stored for parity with the settings screen; there's no rule to enforce for it on check-in.
        $required = array_values(array_diff($required, ['alphanumeric_guest_name']));

        if ($required === []) {
            return [];
        }

        $problems = [];

        if (in_array('guest_document', $required, true) && ! $booking->attachments()->exists()) {
            $problems[] = 'Upload at least one guest document.';
        }

        $guestChecks = [
            'guest_nationality' => ['Nationality', fn (Guest $guest): bool => filled($guest->nationality)],
            'guest_phone' => ['Phone', fn (Guest $guest): bool => filled($guest->phone)],
            'guest_email' => ['E-mail', fn (Guest $guest): bool => collect($guest->emails ?? [])->filter()->isNotEmpty()],
            'guest_address' => ['Address', fn (Guest $guest): bool => filled($guest->address['address'] ?? null) || filled($guest->address['country_id'] ?? null)],
            'guest_dob' => ['Date of birth', fn (Guest $guest): bool => $guest->dob !== null],
            'guest_purpose_of_visit' => ['Purpose of visit', fn (Guest $guest): bool => filled($guest->purpose_of_visit)],
            'guest_gender' => ['Gender', fn (Guest $guest): bool => filled($guest->gender)],
        ];
        $activeChecks = array_intersect_key($guestChecks, array_flip($required));

        if ($activeChecks === []) {
            return $problems;
        }

        $guests = $booking->guests()->get();

        if ($guests->isEmpty()) {
            return [...$problems, 'Add a guest to the booking first.'];
        }

        foreach ($guests as $guest) {
            $missingLabels = collect($activeChecks)->reject(fn (array $check): bool => $check[1]($guest))->map(fn (array $check): string => $check[0]);

            if ($missingLabels->isNotEmpty()) {
                $problems[] = trim("{$guest->first_name} {$guest->last_name}").' is missing: '.$missingLabels->implode(', ').'.';
            }
        }

        return $problems;
    }
}
