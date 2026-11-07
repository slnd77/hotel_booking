<?php

namespace App\Models;

use Database\Factories\NightAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A property's closed business date: what the night audit did (no-shows) and found
 * (overstays), with the day-end report as it stood when the day closed.
 */
class NightAudit extends Model
{
    /** @use HasFactory<NightAuditFactory> */
    use HasFactory;

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'property_id',
        'business_date',
        'status',
        'started_at',
        'finished_at',
        'run_by',
        'summary',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
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
     * Who ran it by hand; null when the scheduler did.
     *
     * @return BelongsTo<User, $this>
     */
    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
