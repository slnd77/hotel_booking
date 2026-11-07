<?php

namespace App\Models;

use Database\Factories\ReportDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One e-mailed report: scheduled (scheduled_for set) or sent with "E-mail now".
 */
class ReportDelivery extends Model
{
    /** @use HasFactory<ReportDeliveryFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'property_id',
        'report',
        'report_date',
        'report_end_date',
        'staff_id',
        'scheduled_for',
        'user_id',
        'recipients',
        'status',
        'error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date',
            'report_end_date' => 'immutable_date',
            'scheduled_for' => 'immutable_date',
            'recipients' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Whose cashier report it is (Daily Cashier Report only).
     *
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * Who pressed "E-mail now"; null for a scheduled send.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
