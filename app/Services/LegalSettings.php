<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\User;

/**
 * Settings → Privacy & Terms: the property's privacy policy and terms of booking (shown to
 * guests on its public pages and accepted at checkout and web check-in), whether guests are
 * asked for marketing consent, and its grievance officer (India's DPDP Act asks for a contact
 * guests can raise data requests with). Plain text; {property} stands for the property's name.
 *
 * The policy version changes whenever the policy or terms text changes, so each consent
 * records which text the guest saw.
 */
class LegalSettings
{
    public const KEY = 'legal_settings';

    private const LABELS = [
        'privacy_policy' => 'Privacy policy',
        'terms' => 'Terms of booking',
        'marketing_opt_in' => 'Ask for marketing consent',
        'grievance_officer_name' => 'Grievance officer',
        'grievance_officer_email' => 'Grievance officer e-mail',
        'grievance_officer_phone' => 'Grievance officer phone',
    ];

    public const DEFAULT_PRIVACY_POLICY = <<<'TEXT'
{property} ("we") collects the personal data you give us when you book or check in: your name, contact details, address, nationality, the identity document the law requires us to record, your stay details and your payments.

We use it only to take and manage your booking, to check you in as the law requires (for example Form C for foreign nationals), to bill you and keep tax records, and to contact you about your stay. With your separate consent we may also send you offers; you can withdraw that consent at any time.

We share your data only with those who need it for your stay: payment gateways that process your payment, booking channels you booked through, and authorities where the law requires. We keep it for as long as your stay and the law (tax and police records) require, and then erase it.

You may ask us for a copy of your data, to correct it, to erase it where the law allows, or to withdraw a consent, by writing to our grievance officer below. If you are not satisfied with our answer you may complain to the Data Protection Board of India (or the data protection authority of your country).
TEXT;

    public const DEFAULT_TERMS = <<<'TEXT'
Your booking with {property} is confirmed once it is paid for as shown at checkout. Rates include the taxes shown; anything you use during your stay is billed at check-out.

Cancellations, changes and no-shows follow the cancellation policy shown when you booked. Refunds go back to the way you paid.

All guests must show a valid photo identity document at check-in. Check-in and check-out times are as shown on your booking; early check-in and late check-out depend on availability and may be charged.

You are responsible for any damage to the property caused during your stay. We may refuse or end a stay that breaks the law or these terms.
TEXT;

    /**
     * @return array{privacy_policy: string, terms: string, marketing_opt_in: bool, grievance_officer_name: string|null, grievance_officer_email: string|null, grievance_officer_phone: string|null, version: string|null}
     */
    public function defaults(): array
    {
        return [
            'privacy_policy' => self::DEFAULT_PRIVACY_POLICY,
            'terms' => self::DEFAULT_TERMS,
            'marketing_opt_in' => true,
            'grievance_officer_name' => null,
            'grievance_officer_email' => null,
            'grievance_officer_phone' => null,
            'version' => null,
        ];
    }

    /**
     * @return array{privacy_policy: string, terms: string, marketing_opt_in: bool, grievance_officer_name: string|null, grievance_officer_email: string|null, grievance_officer_phone: string|null, version: string|null}
     */
    public function get(Property $property): array
    {
        $stored = app(PropertySettings::class)->json($property, self::KEY) ?? [];
        $settings = [...$this->defaults(), ...array_intersect_key($stored, $this->defaults())];
        $settings['marketing_opt_in'] = (bool) $settings['marketing_opt_in'];

        return $settings;
    }

    /**
     * The texts with the property's name in, for guests to read.
     *
     * @return array{privacy_policy: string, terms: string, marketing_opt_in: bool, grievance_officer: array{name: string|null, email: string|null, phone: string|null}, version: string}
     */
    public function forGuests(Property $property): array
    {
        $settings = $this->get($property);
        $fill = fn (string $text): string => str_replace('{property}', $property->name, $text);

        return [
            'privacy_policy' => $fill($settings['privacy_policy']),
            'terms' => $fill($settings['terms']),
            'marketing_opt_in' => $settings['marketing_opt_in'],
            'grievance_officer' => [
                'name' => $settings['grievance_officer_name'],
                'email' => $settings['grievance_officer_email'],
                // Until a grievance officer is set, guests reach the property.
                'phone' => $settings['grievance_officer_phone'] ?: $property->property_contact_number,
            ],
            'version' => $this->version($property),
        ];
    }

    /**
     * Which text a consent was given to: when the policy or terms last changed.
     */
    public function version(Property $property): string
    {
        return $this->get($property)['version'] ?? 'default';
    }

    /**
     * Saves the settings, moving the version on when a text changes, and logs each change.
     *
     * @param  array<string, mixed>  $settings
     */
    public function save(Property $property, array $settings, ?User $user = null): void
    {
        $before = $this->get($property);
        $after = [...$before, ...array_intersect_key($settings, self::LABELS)];

        if ($after['privacy_policy'] !== $before['privacy_policy'] || $after['terms'] !== $before['terms']) {
            $after['version'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Privacy & Terms', 'status' => true])->id;
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($after)]);
        app(PropertySettings::class)->forget($property);

        if ($user === null) {
            return;
        }

        foreach (self::LABELS as $key => $label) {
            if ($before[$key] !== $after[$key]) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'property_id' => $property->id,
                    'action' => in_array($key, ['privacy_policy', 'terms'], true)
                        ? "Privacy & Terms: {$label} updated"
                        : "Privacy & Terms: {$label} changed from ".$this->display($before[$key]).' to '.$this->display($after[$key]),
                    'date' => now(),
                ]);
            }
        }
    }

    private function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            blank($value) => '--',
            default => (string) $value,
        };
    }
}
