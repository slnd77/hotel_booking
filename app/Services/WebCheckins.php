<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Mail\WebCheckinLinkMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\User;
use App\Models\WebCheckin;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Web check-in: guests fill in their details, upload an ID document and sign before arrival,
 * from a link with a random token. Property → Web Check-in sets whether it's on, when the link
 * opens and what it asks for (one JSON document per property in property_meta_fields).
 */
class WebCheckins
{
    public const KEY = 'web_checkin_settings';

    /** Statuses whose guests can still check in online. */
    private const OPEN_STATUSES = [BookingStatus::Confirmed, BookingStatus::Pending, BookingStatus::OnHold];

    public function __construct(private BookingPrints $prints) {}

    /**
     * @return array{enabled: bool, opens_days_before: int, require_id_document: bool, require_signature: bool, require_address: bool, ask_arrival_time: bool, include_in_confirmation_email: bool, instructions: string, terms: string}
     */
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'opens_days_before' => 7,
            'require_id_document' => true,
            'require_signature' => true,
            'require_address' => false,
            'ask_arrival_time' => true,
            'include_in_confirmation_email' => true,
            'instructions' => '',
            'terms' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(Property $property): array
    {
        $stored = app(PropertySettings::class)->json($property, self::KEY) ?? [];
        $settings = [...$this->defaults(), ...array_intersect_key($stored, $this->defaults())];
        $settings['opens_days_before'] = (int) $settings['opens_days_before'];

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(Property $property, array $settings, ?User $user = null): void
    {
        $after = [...$this->settings($property), ...array_intersect_key($settings, $this->defaults())];
        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Web Check-in', 'status' => true])->id;
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($after)]);

        if ($user !== null) {
            ActivityLog::create(['user_id' => $user->id, 'property_id' => $property->id, 'action' => 'Web check-in settings updated', 'date' => now()]);
        }
    }

    public function isEnabled(Property $property): bool
    {
        return (bool) $this->settings($property)['enabled'];
    }

    /**
     * The booking's web check-in, made (with its token) on first use.
     */
    public function for(Booking $booking): WebCheckin
    {
        return $booking->webCheckin()->firstOrCreate([], [
            'property_id' => $booking->property_id,
            'token' => Str::random(48),
            'status' => WebCheckin::PENDING,
        ]);
    }

    public function url(WebCheckin $checkin): string
    {
        return route('web-checkin.show', $checkin->token);
    }

    /**
     * Why the guest can't check in online now, or null when they can.
     */
    public function closedReason(WebCheckin $checkin): ?string
    {
        $booking = $checkin->booking;
        $property = $booking->property;
        $settings = $this->settings($property);

        if (! $settings['enabled']) {
            return 'Online check-in is not available for this hotel.';
        }

        if (! in_array($booking->status, self::OPEN_STATUSES, true)) {
            return "This booking is {$booking->status->label()}; online check-in is closed.";
        }

        $today = CarbonImmutable::parse($property->today());
        $opensOn = CarbonImmutable::parse($booking->check_in->toDateString())->subDays($settings['opens_days_before']);

        if ($today->lt($opensOn)) {
            return 'Online check-in opens on '.$opensOn->format('d M Y').'.';
        }

        if ($today->gt(CarbonImmutable::parse($booking->check_in->toDateString()))) {
            return 'Online check-in closed on your arrival date. Please check in at the front desk.';
        }

        return null;
    }

    /**
     * E-mails the link to the guest (queued) and logs it.
     *
     * @throws ValidationException when there's no address or web check-in is off
     */
    public function send(Booking $booking, ?string $email, ?User $user): WebCheckin
    {
        $booking->loadMissing(['guests', 'property']);

        if (! $this->isEnabled($booking->property)) {
            throw ValidationException::withMessages(['web_checkin' => 'Turn on web check-in under Property → Web Check-in first.']);
        }

        $email = $email ?: ($this->prints->guest($booking->guests->first())['email'] ?? null);

        if (! filled($email)) {
            throw ValidationException::withMessages(['email' => 'The guest has no e-mail address. Enter one.']);
        }

        $checkin = $this->for($booking);
        Mail::to($email)->queue(new WebCheckinLinkMail($checkin));
        $checkin->update(['sent_at' => now()]);

        if ($user === null) {
            return $checkin;
        }

        ActivityLog::create([
            'user_id' => $user->id,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests->first()?->id,
            'action' => "Booking {$booking->booking_ref_id}: web check-in link e-mailed to {$email}.",
            'date' => now(),
        ]);

        return $checkin;
    }

    /**
     * Stores what the guest submitted, updates the guest's profile and marks it completed.
     *
     * @param  array<string, mixed>  $data  validated form data
     */
    public function submit(WebCheckin $checkin, array $data, ?UploadedFile $idDocument, ?string $signatureDataUrl, ?string $ip): WebCheckin
    {
        return DB::transaction(function () use ($checkin, $data, $idDocument, $signatureDataUrl, $ip): WebCheckin {
            $directory = "web-checkins/{$checkin->booking_id}";
            $documentPath = $checkin->id_document_path;
            $signaturePath = $checkin->signature_path;

            if ($idDocument !== null) {
                $documentPath = $idDocument->storeAs($directory, 'id-document-'.Str::random(8).'.'.$idDocument->extension(), 'local');
            }

            if ($signatureDataUrl !== null) {
                $signaturePath = "{$directory}/signature-".Str::random(8).'.png';
                Storage::disk('local')->put($signaturePath, base64_decode(substr($signatureDataUrl, strpos($signatureDataUrl, ',') + 1)));
            }

            $checkin->update([
                'status' => WebCheckin::COMPLETED,
                'details' => $data,
                'id_document_path' => $documentPath,
                'signature_path' => $signaturePath,
                'submitted_at' => now(),
                'submitted_ip' => $ip,
            ]);

            // The guest has no user to log against: when they submitted is on the check-in itself.
            $this->updateGuest($checkin->booking, $data);

            return $checkin->refresh();
        });
    }

    /**
     * The details the front desk sees on the booking.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Booking $booking): ?array
    {
        $property = $booking->property;
        $checkin = $booking->webCheckin;

        return [
            'enabled' => $this->isEnabled($property),
            'url' => $checkin ? $this->url($checkin) : null,
            'status' => $checkin?->status,
            'sent_at' => $checkin?->sent_at?->toIso8601ZuluString(),
            'submitted_at' => $checkin?->submitted_at?->toIso8601ZuluString(),
            'details' => $checkin?->details,
            'has_id_document' => filled($checkin?->id_document_path),
            'has_signature' => filled($checkin?->signature_path),
            'closed_reason' => $checkin ? $this->closedReason($checkin) : null,
        ];
    }

    /**
     * The primary guest takes the submitted name, contact and address (a booking without a
     * guest gets one).
     *
     * @param  array<string, mixed>  $data
     */
    private function updateGuest(Booking $booking, array $data): void
    {
        $guest = $booking->guests()->first();
        $attributes = array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'nationality' => $data['nationality'] ?? null,
        ], fn ($value): bool => filled($value));

        if (filled($data['email'] ?? null)) {
            $attributes['emails'] = collect($guest->emails ?? [])->prepend($data['email'])->unique()->values()->all();
        }

        if (filled($data['address'] ?? null)) {
            $attributes['address'] = [...(is_array($guest?->address) ? $guest->address : []), 'address' => $data['address'], ...(filled($data['postal_code'] ?? null) ? ['postal_code' => $data['postal_code']] : [])];
        }

        if (filled($data['passport_number'] ?? null)) {
            $attributes['international_details'] = [...(is_array($guest?->international_details) ? $guest->international_details : []), 'passport_number' => $data['passport_number']];
        }

        if ($guest instanceof Guest) {
            $guest->update($attributes);

            return;
        }

        $guest = Guest::query()->create(['property_id' => $booking->property_id, ...$attributes]);
        $booking->guests()->attach($guest->id);
    }
}
