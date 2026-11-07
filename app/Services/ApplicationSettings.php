<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingLog;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Account-wide settings (Manage Account → Application Settings), stored one
 * JSON document per section in `application_settings`.
 */
class ApplicationSettings
{
    public const RESERVATION = 'reservation';

    public const GUEST = 'guest';

    public const WIDGET = 'widget';

    public const DAY_POSTING = 'day_posting';

    public const BLOCK_UNBLOCK = 'block_unblock';

    public const SMART_CHART = 'smart_chart';

    public const NOTIFICATION = 'notification';

    /** Manage Account → Data & privacy: how long guests' personal data and logs are kept. */
    public const DATA_RETENTION = 'data_retention';

    /**
     * Notification channels in display order; only e-mail can be used so far.
     *
     * @var array<string, array{label: string, supported: bool}>
     */
    public const NOTIFICATION_CHANNELS = [
        'email' => ['label' => 'E-mail', 'supported' => true],
        'sms' => ['label' => 'SMS', 'supported' => false],
        'whatsapp' => ['label' => 'WhatsApp', 'supported' => false],
    ];

    /**
     * E-mails sent to guests on their own (each can be switched off), in display order.
     *
     * @var array<string, string>
     */
    public const GUEST_EMAILS = [
        'booking_confirmation' => 'Booking confirmation',
        'booking_cancellation' => 'Booking cancellation',
        'payment_receipt' => 'Payment receipt',
    ];

    /**
     * Mailer registered from the e-mail channel's SMTP settings.
     */
    public const NOTIFICATION_MAILER = 'notification_smtp';

    public const SMART_CHART_MIN_DAYS = 5;

    public const SMART_CHART_MAX_DAYS = 20;

    public const SMART_CHART_MIN_REFRESH_MINUTES = 1;

    public const SMART_CHART_MAX_REFRESH_MINUTES = 60;

    /**
     * Guest default nationality meaning "the country of the property the guest is created for".
     */
    public const PROPERTY_COUNTRY = 'property_country';

    /**
     * @var array{default: mixed, from: mixed}|null
     */
    private static ?array $envMailConfig = null;

    /**
     * Defaults reproduce the app's behaviour before these settings existed
     * (e.g. guests already required a phone number).
     *
     * @return array<string, mixed>
     */
    public function defaults(string $section): array
    {
        return match ($section) {
            self::RESERVATION => [
                'check_in' => [
                    'guest_nationality' => false,
                    'guest_phone' => false,
                    'guest_email' => false,
                    'alphanumeric_guest_name' => false,
                    'guest_document' => false,
                    'guest_address' => false,
                ],
                'check_out' => [
                    'guest_nationality' => false,
                    'guest_dob' => false,
                    'guest_purpose_of_visit' => false,
                    'guest_gender' => false,
                ],
                'subtypes' => [
                    ['value' => 'walk_in', 'label' => 'Walk-in', 'active' => true],
                    ['value' => 'phone', 'label' => 'Phone', 'active' => true],
                    ['value' => 'email', 'label' => 'E-mail', 'active' => true],
                    ['value' => 'whatsapp', 'label' => 'WhatsApp', 'active' => true],
                ],
            ],
            self::GUEST => [
                'enable_tracker_number' => false,
                'tracker_prefix' => '',
                'tracker_start_number' => 1,
                'mandatory' => [
                    'first_name' => true,
                    'last_name' => false,
                    'phone' => true,
                    'email' => false,
                    'phone_or_email' => false,
                    'update_with_document' => false,
                ],
                'default_nationality' => self::PROPERTY_COUNTRY,
            ],
            self::WIDGET => ['property_order' => []],
            self::DAY_POSTING => ['transaction_posting_time' => '00:00'],
            self::SMART_CHART => ['days_to_show' => 10, 'auto_refresh_minutes' => 2],
            self::NOTIFICATION => [
                'email' => [
                    'enabled' => false,
                    'smtp' => [
                        'host' => '',
                        'port' => 587,
                        'encryption' => 'tls',
                        'username' => '',
                        'password' => null,
                        'from_address' => '',
                        'from_name' => '',
                    ],
                ],
                'sms' => ['enabled' => false],
                'whatsapp' => ['enabled' => false],
                'guest_emails' => array_fill_keys(array_keys(self::GUEST_EMAILS), true),
            ],
            // Null keeps the data: removing it is the account owner's choice.
            self::DATA_RETENTION => [
                'guest_data_months' => null,
                'id_document_days' => null,
                'activity_log_months' => null,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $section): array
    {
        $stored = ApplicationSetting::query()->where('key', $section)->value('value');

        return $this->mergeWithDefaults($this->defaults($section), is_array($stored) ? $stored : (json_decode((string) $stored, true) ?? []));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function save(string $section, array $value, ?User $user, string $description): void
    {
        ApplicationSetting::query()->updateOrCreate(['key' => $section], ['value' => $value]);
        $this->log($section, $user, $description);
    }

    public function log(string $section, ?User $user, string $description): void
    {
        ApplicationSettingLog::create(['section' => $section, 'user_id' => $user?->id, 'description' => $description]);
    }

    /**
     * @return Collection<int, array{description: string, user: string|null, at: string|null}>
     */
    public function logs(string $section, int $limit = 50): Collection
    {
        return ApplicationSettingLog::query()
            ->where('section', $section)
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ApplicationSettingLog $log): array => [
                'description' => $log->description,
                'user' => $log->user?->name,
                'at' => $log->created_at?->toIso8601ZuluString(),
            ]);
    }

    /**
     * Next guest tracker number (prefix + running number), or null while the
     * feature is off. The counter is locked so concurrent guests never share one.
     */
    public function nextGuestTrackerNumber(): ?string
    {
        $settings = $this->get(self::GUEST);

        if (! $settings['enable_tracker_number']) {
            return null;
        }

        return DB::transaction(function () use ($settings): string {
            $counter = ApplicationSetting::query()->where('key', 'guest_tracker_sequence')->lockForUpdate()->first();
            $next = max((int) ($counter?->value['next'] ?? 0), (int) $settings['tracker_start_number'], 1);

            ApplicationSetting::query()->updateOrCreate(['key' => 'guest_tracker_sequence'], ['value' => ['next' => $next + 1]]);

            return $settings['tracker_prefix'].$next;
        });
    }

    /**
     * Properties in Application Settings → Widget order; properties not yet
     * placed there (e.g. newly added) follow alphabetically.
     *
     * @param  list<string>  $columns
     * @return EloquentCollection<int, Property>
     */
    public function orderedProperties(array $columns = ['id', 'name', 'abbreviation']): EloquentCollection
    {
        $order = array_flip($this->get(self::WIDGET)['property_order']);

        return Property::query()->orderBy('name')->get($columns)
            ->sortBy(fn (Property $property): int => $order[$property->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Days shown on the smart booking chart, kept within the allowed range.
     */
    public function smartChartDays(): int
    {
        return max(self::SMART_CHART_MIN_DAYS, min(self::SMART_CHART_MAX_DAYS, (int) $this->get(self::SMART_CHART)['days_to_show']));
    }

    /**
     * Minutes between the smart booking chart's automatic refreshes, kept within the allowed range.
     */
    public function smartChartRefreshMinutes(): int
    {
        return max(self::SMART_CHART_MIN_REFRESH_MINUTES, min(self::SMART_CHART_MAX_REFRESH_MINUTES, (int) $this->get(self::SMART_CHART)['auto_refresh_minutes']));
    }

    /**
     * Only "Type" subtypes switched on, for the booking form.
     *
     * @return list<array{value: string, label: string}>
     */
    public function activeReservationSubtypes(): array
    {
        return collect($this->get(self::RESERVATION)['subtypes'])
            ->filter(fn (array $subtype): bool => (bool) ($subtype['active'] ?? false))
            ->map(fn (array $subtype): array => ['value' => (string) $subtype['value'], 'label' => (string) $subtype['label']])
            ->values()
            ->all();
    }

    /**
     * Channels for the Notification Settings table. The SMTP password is never
     * sent to the browser; `has_password` says whether one is saved.
     *
     * @return list<array{key: string, label: string, supported: bool, enabled: bool, configured: bool, smtp?: array<string, mixed>}>
     */
    public function notificationChannels(): array
    {
        $settings = $this->get(self::NOTIFICATION);

        return collect(self::NOTIFICATION_CHANNELS)->map(function (array $channel, string $key) use ($settings): array {
            $row = [
                'key' => $key,
                'label' => $channel['label'],
                'supported' => $channel['supported'],
                'enabled' => $channel['supported'] && (bool) ($settings[$key]['enabled'] ?? false),
                'configured' => $key === 'email' && $this->isEmailConfigured($settings['email']['smtp']),
            ];

            if ($key === 'email') {
                $smtp = $settings['email']['smtp'];
                $row['smtp'] = [...Arr::except($smtp, 'password'), 'has_password' => filled($smtp['password'])];
            }

            return $row;
        })->values()->all();
    }

    /**
     * Channels a hold / enquiry reminder can be sent through: switched on and configured.
     *
     * @return list<array{value: string, label: string}>
     */
    public function reminderChannels(): array
    {
        return collect($this->notificationChannels())
            ->filter(fn (array $channel): bool => $channel['enabled'] && $channel['configured'])
            ->map(fn (array $channel): array => ['value' => $channel['key'], 'label' => $channel['label']])
            ->values()
            ->all();
    }

    /**
     * Whether the guest e-mail ($email: a GUEST_EMAILS key) is switched on.
     */
    public function sendsGuestEmail(string $email): bool
    {
        return (bool) ($this->get(self::NOTIFICATION)['guest_emails'][$email] ?? false);
    }

    /**
     * @param  array<string, mixed>  $smtp
     */
    public function isEmailConfigured(array $smtp): bool
    {
        return filled($smtp['host'] ?? null) && filled($smtp['port'] ?? null) && filled($smtp['from_address'] ?? null);
    }

    /**
     * Laravel mailer config built from the saved SMTP settings (password decrypted).
     *
     * @param  array<string, mixed>  $smtp
     * @return array<string, mixed>
     */
    public function smtpMailerConfig(array $smtp): array
    {
        return [
            'transport' => 'smtp',
            'host' => $smtp['host'],
            'port' => (int) $smtp['port'],
            'scheme' => $smtp['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'username' => filled($smtp['username']) ? $smtp['username'] : null,
            'password' => filled($smtp['password']) ? Crypt::decryptString($smtp['password']) : null,
            'timeout' => 15,
        ];
    }

    /**
     * When the e-mail channel is on, all application mail goes out through its SMTP server
     * and from its sender; otherwise the .env mail settings stay in use.
     */
    public function applyEmailChannelToMailConfig(): void
    {
        // The .env mailer and sender, kept so a later account (a queue worker runs many) without
        // its own SMTP server doesn't inherit the previous one's.
        self::$envMailConfig ??= ['default' => config('mail.default'), 'from' => config('mail.from')];
        config(['mail.default' => self::$envMailConfig['default'], 'mail.from' => self::$envMailConfig['from']]);

        $email = $this->get(self::NOTIFICATION)['email'];

        if (! $email['enabled'] || ! $this->isEmailConfigured($email['smtp'])) {
            return;
        }

        config([
            'mail.mailers.'.self::NOTIFICATION_MAILER => $this->smtpMailerConfig($email['smtp']),
            'mail.default' => self::NOTIFICATION_MAILER,
            'mail.from.address' => $email['smtp']['from_address'],
            'mail.from.name' => filled($email['smtp']['from_name']) ? $email['smtp']['from_name'] : config('mail.from.name'),
        ]);
    }

    /**
     * Keyed (object) defaults merge recursively; lists and scalars are replaced whole.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function mergeWithDefaults(array $defaults, array $stored): array
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            $defaults[$key] = is_array($default) && ! array_is_list($default) && is_array($stored[$key])
                ? $this->mergeWithDefaults($default, $stored[$key])
                : $stored[$key];
        }

        return $defaults;
    }
}
