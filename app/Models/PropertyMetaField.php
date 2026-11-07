<?php

namespace App\Models;

use Database\Factories\PropertyMetaFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyMetaField extends Model
{
    /** @use HasFactory<PropertyMetaFieldFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'meta_fields_key_id',
        'enabled',
        'data',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
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
     * @return BelongsTo<MetaField, $this>
     */
    public function metaField(): BelongsTo
    {
        return $this->belongsTo(MetaField::class, 'meta_fields_key_id');
    }
}
