<?php

namespace App\Models;

use App\Enums\PaymentLinkStatus;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A "Collect Online" payment link for part or all of a booking's outstanding
 * amount. The public URL only carries the random token; the amount stays here.
 */
class PaymentLink extends Model
{
    /** @use HasFactory<PaymentLinkFactory> */
    use HasFactory;

    /**
     * Days a new link stays payable.
     */
    public const VALIDITY_DAYS = 7;

    protected $fillable = [
        'token',
        'property_id',
        'booking_id',
        'created_by',
        'collect_for',
        'amount',
        'currency',
        'email',
        'mobile',
        'status',
        'expires_at',
        'sent_at',
        'payment_gateway',
        'gateway_order_id',
        'transaction_id',
        'payment_id',
        'paid_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentLink $link): void {
            $link->token ??= Str::random(48);
            $link->expires_at ??= now()->addDays(self::VALIDITY_DAYS);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentLinkStatus::class,
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
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
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function url(): string
    {
        return route('payment-links.show', $this->token);
    }

    public function isExpired(): bool
    {
        return $this->status === PaymentLinkStatus::Expired
            || ($this->status === PaymentLinkStatus::Pending && $this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isPayable(): bool
    {
        return $this->unpayableReason() === null;
    }

    /**
     * Why the guest can't pay this link right now, or null when they can. A link
     * only works while the booking still owes at least the link's amount, so a
     * booking settled in another way is never paid twice.
     */
    public function unpayableReason(): ?string
    {
        if ($this->status === PaymentLinkStatus::Paid) {
            return 'This payment link has already been paid.';
        }

        if ($this->status === PaymentLinkStatus::Cancelled) {
            return 'This payment link has been cancelled.';
        }

        if ($this->isExpired()) {
            return 'This payment link has expired.';
        }

        $outstanding = round((float) $this->booking->outstanding, 2);

        if ($outstanding <= 0) {
            return 'There is no outstanding amount left to pay for this booking.';
        }

        if ((float) $this->amount > $outstanding) {
            return 'The amount due for this booking has changed since this link was created.';
        }

        return null;
    }

    /**
     * Status as the guest should see it: a pending link past its expiry reads as expired.
     */
    public function effectiveStatus(): PaymentLinkStatus
    {
        return $this->isExpired() ? PaymentLinkStatus::Expired : $this->status;
    }
}
