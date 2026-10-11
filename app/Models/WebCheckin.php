<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking's web check-in: the link the guest opens before arrival (by its random token) and
 * what they submitted (see App\Services\WebCheckins).
 */
class WebCheckin extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    protected $fillable = [
        'property_id',
        'booking_id',
        'token',
        'status',
        'details',
        'id_document_path',
        'signature_path',
        'sent_at',
        'submitted_at',
        'submitted_ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'sent_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
