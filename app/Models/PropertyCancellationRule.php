<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyCancellationRule extends Model
{
    protected $fillable = [
        'property_id',
        'start_time_stamp',
        'end_time_stamp',
        'rules',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time_stamp' => 'datetime',
            'end_time_stamp' => 'datetime',
            'rules' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
