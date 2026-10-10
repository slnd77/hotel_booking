<?php

namespace App\Models;

use Database\Factories\ChannexBookingRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an OTA booking received from Channex (new, modified or cancelled), kept
 * with its raw payload and the booking it was applied to.
 */
class ChannexBookingRevision extends Model
{
    /** @use HasFactory<ChannexBookingRevisionFactory> */
    use HasFactory;

    protected $fillable = [
        'channex_connection_id',
        'property_id',
        'revision_id',
        'channex_booking_id',
        'status',
        'ota_name',
        'ota_reservation_code',
        'booking_id',
        'payload',
        'processed_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChannexConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannexConnection::class, 'channex_connection_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
