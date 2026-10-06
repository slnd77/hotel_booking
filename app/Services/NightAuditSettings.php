<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MetaField;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Night Audit ("Rollover Configuration"), one JSON document per
 * property in property_meta_fields. Dates are the property's business dates and the
 * rollover time is its wall-clock time; they only become UTC instants when compared.
 */
class NightAuditSettings
{
    public const KEY = 'night_audit_settings';

    public const TYPE_OFF = 'off';

    public const TYPE_AUTOMATED = 'automated';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_OFF => 'Night Audit Off',
        self::TYPE_AUTOMATED => 'Automated Night Audit',
    ];

    /** Field labels, for the activity log. */
    public const LABELS = [
        'rollover_type' => 'Rollover Types',
        'current_rollover_date' => 'Current Rollover Date',
        'default_rollover_time' => 'Default Roll-over Time',
        'pre_rollover_notification' => 'Pre-rollover Notification',
        'restrict_reports' => 'Restrict Reports',
        'validate_excess_payment' => 'Validate Excess Payment',
        'freeze_past_dated_data' => 'Freeze all past dated data',
        'payment_reconciliation' => 'Payment Reconciliation Flag',
        'pos_rollover' => 'Pos Roll Over Flag',
        'require_cashier_shift' => 'Require Open Cashier Shift',
        'excess_payment_tolerance' => 'Excess Payment Tolerance Value',
        'validate_invoice' => 'Validate Invoice',
        'invoice_start_date' => 'Invoice Start Date Validation',
        'pos_rollover_start_date' => 'POS Rollover Start Date',
        'room_rollover_start_date' => 'Room Rollover Start Date',
    ];

    private const LOG_PREFIX = 'Night audit settings';

    public function __construct(private ApplicationSettings $applicationSettings) {}

    /**
     * @return array{rollover_type: string, current_rollover_date: string|null, default_rollover_time: string, pre_rollover_notification: int|null, restrict_reports: bool, validate_excess_payment: bool, freeze_past_dated_data: bool, payment_reconciliation: bool, pos_rollover: bool, require_cashier_shift: bool, excess_payment_tolerance: float, validate_invoice: bool, invoice_start_date: string|null, pos_rollover_start_date: string|null, room_rollover_start_date: string|null}
     */
    public function defaults(): array
    {
        return [
            'rollover_type' => self::TYPE_OFF,
            'current_rollover_date' => null,
            // Until set here, the day rolls over at the account's Day Posting time.
            'default_rollover_time' => (string) ($this->applicationSettings->get(ApplicationSettings::DAY_POSTING)['transaction_posting_time'] ?? '00:00'),
            'pre_rollover_notification' => null,
            'restrict_reports' => false,
            'validate_excess_payment' => false,
            'freeze_past_dated_data' => false,
            'payment_reconciliation' => false,
            'pos_rollover' => false,
            'require_cashier_shift' => false,
            'excess_payment_tolerance' => 1.0,
            'validate_invoice' => false,
            'invoice_start_date' => null,
            'pos_rollover_start_date' => null,
            'room_rollover_start_date' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Property $property): array
    {
        $stored = app(PropertySettings::class)->json($property, self::KEY) ?? [];

        $settings = [...$this->defaults(), ...array_intersect_key($stored, $this->defaults())];
        $settings['excess_payment_tolerance'] = (float) $settings['excess_payment_tolerance'];

        return $settings;
    }

    /**
     * Saves the settings and logs each changed field (with the user, when there is one).
     *
     * @param  array<string, mixed>  $settings
     */
    public function save(Property $property, array $settings, ?User $user = null): void
    {
        $before = $this->get($property);
        $after = [...$before, ...array_intersect_key($settings, $this->defaults())];

        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Night Audit', 'status' => true])->id;
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($after)]);

        if ($user === null) {
            return;
        }

        foreach ($after as $key => $value) {
            if ($this->display($before[$key]) !== $this->display($value)) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'property_id' => $property->id,
                    'action' => self::LOG_PREFIX.': '.self::LABELS[$key].' changed from '.$this->display($before[$key]).' to '.$this->display($value),
                    'date' => now(),
                ]);
            }
        }
    }

    /**
     * @return Collection<int, array{action: string, user: string|null, at: string}>
     */
    public function logs(Property $property, int $limit = 50): Collection
    {
        return ActivityLog::query()
            ->where('property_id', $property->id)
            ->whereLike('action', self::LOG_PREFIX.'%')
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'action' => substr($log->action, strlen(self::LOG_PREFIX) + 2),
                'user' => $log->user?->name,
                'at' => $log->date->toIso8601ZuluString(),
            ]);
    }

    public function isAutomated(Property $property): bool
    {
        return $this->get($property)['rollover_type'] === self::TYPE_AUTOMATED;
    }

    /**
     * The business date the property is on — the next one the night audit closes:
     * the configured Current Rollover Date, else the day after the last one closed,
     * else the property's today.
     */
    public function currentRolloverDate(Property $property): CarbonImmutable
    {
        $configured = $this->get($property)['current_rollover_date'];
        $afterLastClosed = $this->lastClosedDate($property)?->addDay()->toDateString();
        $candidates = array_filter([$configured, $afterLastClosed]);
        $date = $candidates === [] ? $property->today() : max($candidates);

        return CarbonImmutable::parse($date)->startOfDay();
    }

    /**
     * The last business date the night audit closed, if any.
     */
    public function lastClosedDate(Property $property): ?CarbonImmutable
    {
        $lastClosed = NightAudit::query()->where('property_id', $property->id)->where('status', NightAudit::COMPLETED)->max('business_date');

        return $lastClosed ? CarbonImmutable::parse($lastClosed)->startOfDay() : null;
    }

    /**
     * "Restrict Reports": dated reports only cover business dates the night audit has closed,
     * so nobody reads (or e-mails) figures of a day that can still change. Null: no limit.
     */
    public function reportsClosedUntil(Property $property): ?CarbonImmutable
    {
        if (! $this->get($property)['restrict_reports']) {
            return null;
        }

        return $this->lastClosedDate($property) ?? CarbonImmutable::create(1970, 1, 1);
    }

    /**
     * @throws ValidationException when Restrict Reports is on and the dates reach past the last closed day
     */
    public function ensureReportable(Property $property, CarbonImmutable $date, ?CarbonImmutable $endDate = null): void
    {
        $until = $this->reportsClosedUntil($property);

        if ($until === null || ($endDate ?? $date)->toDateString() <= $until->toDateString()) {
            return;
        }

        $lastClosed = $this->lastClosedDate($property);

        throw ValidationException::withMessages(['date' => $lastClosed === null
            ? 'Reports only cover business dates the night audit has closed, and none has been closed yet.'
            : "Reports only cover business dates the night audit has closed: up to {$lastClosed->format('d/m/Y')}."]);
    }

    /**
     * When business date D rolls over: the next day at the rollover time, in the property's
     * zone (so a DST change moves the UTC instant, not the wall-clock time).
     */
    public function rolloverAt(Property $property, CarbonImmutable $businessDate): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->get($property)['default_rollover_time'] ?: '00:00') + [0, 0]);

        return CarbonImmutable::create($businessDate->year, $businessDate->month, $businessDate->day, 0, 0, 0, $property->timezoneName())
            ->addDay()
            ->setTime($hour, $minute)
            ->utc();
    }

    /**
     * After closing D the property moves on to D + 1.
     */
    public function advance(Property $property, CarbonImmutable $closedDate): void
    {
        $next = $closedDate->addDay()->toDateString();

        if (($this->get($property)['current_rollover_date'] ?? '') < $next) {
            $this->save($property, ['current_rollover_date' => $next]);
        }
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_string($value) && isset(self::TYPES[$value]) => self::TYPES[$value],
            default => (string) $value,
        };
    }
}
