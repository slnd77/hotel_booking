<?php

namespace App\Models;

use Database\Factories\ChartNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note a user leaves on one day of a property's smart booking chart.
 */
class ChartNote extends Model
{
    /** @use HasFactory<ChartNoteFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'user_id',
        'date',
        'body',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The note as the chart shows it.
     *
     * @return array{id: int, user_id: int, user_name: string, body: string, updated_at: string|null}
     */
    public function toChartArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name ?? 'Deleted user',
            'body' => $this->body,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
