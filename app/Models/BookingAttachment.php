<?php

namespace App\Models;

use Database\Factories\BookingAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest document (ID proof etc.) uploaded against a booking. Files live on
 * the private `local` disk under `booking-attachments/{booking_id}`.
 */
class BookingAttachment extends Model
{
    /** @use HasFactory<BookingAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'user_id',
        'document_type',
        'document_number',
        'expiry_date',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'size' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
