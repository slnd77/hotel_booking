<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contact person at an agency (one row on Databases → Agents). Deleting one is a soft
 * delete: it leaves the list and the booking form's picker, but past bookings keep it.
 *
 * @property int|null $created_by the user who added the contact (a users.id)
 * @property int|null $updated_by the user who last changed the contact (a users.id)
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'contact_name',
        'phone_country_code',
        'phone',
        'emails',
    ];

    protected static function booted(): void
    {
        // Who added / last changed the contact, for its Agent History.
        static::creating(function (Agent $agent): void {
            $agent->created_by ??= auth()->user()?->id;
            $agent->updated_by ??= auth()->user()?->id;
        });

        static::updating(function (Agent $agent): void {
            $userId = auth()->user()?->id;

            if ($userId !== null) {
                $agent->updated_by = $userId;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emails' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * How the booking form's agent picker lists the contact.
     *
     * @return array{id: int, contact_name: string, agency_name: string|null, agency_code: string|null, email: string|null, phone: string|null}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'contact_name' => $this->contact_name,
            'agency_name' => $this->agency?->name,
            'agency_code' => $this->agency?->code,
            'email' => $this->emails[0] ?? null,
            'phone' => $this->phoneNumber(),
        ];
    }

    public function phoneNumber(): ?string
    {
        return filled($this->phone) ? trim(($this->phone_country_code ?? '').' '.$this->phone) : null;
    }
}
